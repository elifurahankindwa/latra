# Data Lifecycle, Retention & Background Processing

How the integration keeps its database bounded, why the database used to grow
without limit (and cause HTTP 524 / 504 gateway timeouts), and how to operate,
verify, and roll back the fix.

Related: [`PERFORMANCE_FIX_REPORT.md`](PERFORMANCE_FIX_REPORT.md) (the index/cache
pass that fixed the *first* 524 — cold dashboard loads). This document covers the
*second* half: the tables that were never pruned at all.

---

## 1. TL;DR

| Area | Before | After |
|---|---|---|
| `message_log` growth | only `latra_status='sent'` rows were ever removed; activity-1 movement logging, `rejected`, and non-bucketed rows were **immortal** | chunked retention per status/window; nothing in the queue is touched |
| `emergency_queue` | 100% `pending`, never drained, event stuck `active` forever | idle events auto-closed, buffer pruned once the event ends |
| `validation_errors`, `push_log`, `job_runs`, `audit_log`, archives | no retention at all | per-table windows (see §5) |
| Retention execution | one unbounded `INSERT … SELECT` + `DELETE` (giant transaction / long lock) | chunked `DELETE … LIMIT`, wall-clock budget, per-step isolation |
| Long HTTP operations | `sync_wialon`, `bulk_fetch_messages`, `bulk_push_activity` ran to completion in the request | handed to a background worker with a hard SSE budget |
| GPS poll | 1 Wialon login **per vehicle** per cycle (405 logins) | 1 shared session per cycle |
| GPS health refresh | N+1 (`SELECT … MAX()` per vehicle) | one grouped query + atomic upsert |
| Daily KPI rollup | `DATE(created_at) = CURDATE()` (non-sargable, full scan) | range predicate (index-friendly) |

All changes are additive and reversible; no permanent business table is dropped
or restructured.

---

## 2. Root causes found

1. **`emergency_queue` never drained.** `EmergencyHandler::flushPending()` exists
   but is never called, so buffered emergency records accumulated as `pending`
   forever (2,765 rows / ~5 MB in the reference DB).
2. **Stuck-active emergency events.** Six events had been `active` since the
   data stopped (2026-10-05) with no idle timeout. Each one held its vehicle in
   the 1 Hz `emergency_poll` loop indefinitely — a self-sustaining feedback loop
   that kept generating `message_log` + `emergency_queue` rows.
3. **`message_log` non-deliverable rows were immortal.** `purgeDelivered()` only
   deleted `latra_status='sent'`. Activity-1 movement logging, `rejected`, and
   rows outside any enabled bucket never became `sent`, so they grew forever.
4. **No retention on `validation_errors`, `push_log`, `wialon_events`,
   `job_runs`, `audit_log`, or the archive tables.**
5. **Unbounded retention.** `jobRetention()` ran `INSERT … SELECT` + `DELETE`
   over the entire age-out set in one statement, and `scripts/maintain.php` ran
   single `DELETE`s — a huge lock / rollback-log spike on a large table.
6. **Long-running HTTP requests.** `sync_wialon` (405 Wialon units), and the SSE
   `bulk_fetch_messages` / `bulk_push_activity` loops had no wall-clock cap, so
   they could be cut off by the gateway (524/504).
7. **N+1 Wialon logins.** `jobPoll()` logged in/out once per vehicle.
8. **N+1 GPS health.** `GpsMonitor::refresh()` ran a `MAX()` query per vehicle.
9. **Non-sargable rollup.** `DATE(created_at) = CURDATE()` prevented index use.
10. **Missing indexes** on `created_at`-style predicates (fixed in schema v10,
    extended in v11).

---

## 3. Data classification

The engine only ever touches **temporary / ingest-history** tables. Nothing else.

| Class | Examples | Policy |
|---|---|---|
| **Permanent business data** | `vehicles`, `activity_definitions`, `activity_mappings`, `automation_buckets`, `bucket_vehicles`, `hardware_fault_codes`, `app_users`, `config` | **Never deleted** |
| **Derived current state** (bounded by fleet size — upserted, not appended) | `vehicle_state`, `gps_health`, `device_status` | **Never deleted** (one row per vehicle) |
| **Temporary / ingest history** (grows with traffic) | `message_log`, `emergency_queue`, `emergency_events`, `validation_errors`, `push_log`, `wialon_events`, `outbox`, `job_runs`, `automation_runs`, `automation_run_items`, `audit_log`, `webhook_events`, `rate_limits`, `ai_chat_log`, `ai_conversations`, archives | **Pruned by §5** |

The safe-delete invariant: a `message_log` row is *never* deleted while it is
referenced by the `outbox` (i.e. still awaiting LATRA delivery). The
`stale_pending` step uses `LEFT JOIN outbox … WHERE o.id IS NULL`, so any row in
the retry queue is preserved regardless of age.

---

## 4. Retention engine — `includes/Retention.php`

`Retention::runAll($overrides)` runs a list of independent steps. Each step:

* deletes in **bounded batches** of `batch` rows (`DELETE … LIMIT`), never one
  giant statement;
* stops at a **wall-clock budget** (`time_budget`, default 25 s) and resumes the
  remainder on the next run — progress is never lost;
* is **isolated**: a failure in one step is logged and the rest continue;
* is **idempotent / safe to re-run** — nothing here creates duplicates or
  requires a prior partial run to be rolled back;
* records its result in `retention_log` and the JSON logger.

MariaDB cannot `LIMIT` a multi-table `DELETE`, so join-driven steps
(`stale_pending`, `emergency_queue`) select a bounded page of primary keys and
delete by id — see `Retention::deleteBySelect()`.

Archiving is also batched (`archiveBatched`) and only deletes a row **after**
confirming it is present in the archive (`INSERT IGNORE` on the shared PK), so an
interrupted copy can never lose data.

### Dry run

Every step supports `dry_run`, which reports the exact row count that *would* be
affected without deleting anything:

```bash
php scripts/maintain.php --dry-run
# or
php -r 'require "config.php"; print_r(Retention::runAll(["dry_run" => true]));'
```

---

## 5. Retention windows (defaults)

All values live in the `config` table and are editable in-app / via SQL.

| Config key | Default | Applies to |
|---|---|---|
| `retention_days` | 90 | hot `message_log` window; rows older are archived before any delete |
| `delivered_retention_hours` | 24 | `message_log` rows LATRA confirmed (`sent`, `pushed_at`) |
| `rejected_retention_days` | 30 | `message_log` `rejected` (never deliverable, kept for audit) |
| `stale_pending_retention_days` | 7 | `pending`/`failed` rows **not** referenced by `outbox` |
| `validation_error_retention_days` | 30 | `validation_errors` |
| `emergency_retention_days` | 7 | ended `emergency_events` + their `emergency_queue` records |
| `emergency_idle_seconds` | 900 | auto-close an `active` event idle this long |
| `push_log_retention_days` | 30 | `push_log`, terminal (`sent`/`rejected`) `outbox` |
| `webhook_retention_days` | 14 | `webhook_events` |
| `rate_limit_retention_days` | 14 | `rate_limits` |
| `job_run_retention_days` | 60 | `job_runs`, `automation_runs` (cascades `automation_run_items`) |
| `audit_retention_days` | 365 | `audit_log`, and the engine's own `retention_log` |
| `ai_retention_days` | 180 | `ai_chat_log`, `ai_conversations` |
| `archive_days` | 180 | `message_log_archive`, `push_log_archive` |
| `archive_hot_messages` | 1 | move hot `message_log` rows to the archive before deleting |

### Steps executed

```
message_log.delivered       message_log.rejected      message_log.stale_pending
validation_errors           push_log                  wialon_events
outbox.sent                 outbox.rejected           emergency_queue
emergency_events            job_runs                  automation_runs
audit_log                   webhook_events            rate_limits
ai_chat_log                 ai_conversations
message_log.archive         message_log_archive       push_log_archive
retention_log
```

**Note on archiving.** With the per-status windows above active, the hot
`message_log` is bounded well before `retention_days` (90), so the
`message_log.archive` step normally moves **nothing** — it is a backstop for the
case where an operator raises `delivered_retention_hours` /
`stale_pending_retention_days` far enough that rows would otherwise survive past
the hot window. Rows still referenced by the `outbox` are never archived or
deleted. Set `archive_hot_messages = 0` to disable archiving entirely (rows past
the hot window are then left alone rather than moved).

---

## 6. Background task queue — `includes/BackgroundTasks.php`

Heavy work that used to run inside an HTTP request is enqueued and executed by
the scheduler worker.

* **Types:** `wialon_sync` (fleet sync), `wialon_fetch` (bulk telemetry pull),
  `latra_push` (bulk LATRA submission).
* **Claiming** mirrors `Outbox::claimBatch()`: one atomic `UPDATE` stamps a
  random `batch_token`, then the worker reads back its own rows (MariaDB 10.4 has
  no `SELECT … SKIP LOCKED`).
* **Leases:** a claimed task holds `locked_until`; `reapStale()` returns tasks
  from a dead worker to `pending`.
* **Retries:** `attempts` increments on each claim; `fail()` retries with a
  backoff (`min(300, 15 × attempts)`) until `max_attempts`, then marks `failed`
  and emits a `background_task_failed` webhook.
* **Continuations:** if a batch is cut short by the per-task time budget, the
  unprocessed vehicle ids are re-enqueued automatically, so nothing is dropped.
* **Bounded:** `runDue()` runs at most `background_task_batch` tasks within a
  `background_task_seconds` wall-clock budget per tick.

### Graceful fallback (no worker installed)

`BackgroundTasks::workerActive()` reads `LOGS_DIR/last_tick.txt` (the same
heartbeat the health page uses). If no worker has ticked recently, the API runs
the operation **inline** as before — so a fresh deployment with no cron still
works. Once cron/worker is running, the same button transparently switches to
the background.

### API

| Action | Method | Purpose |
|---|---|---|
| `sync_wialon` | POST | enqueue `wialon_sync` (or inline if no worker); returns `{queued, task_id}` |
| `task_status` | GET/POST | poll a task: `{id, task_type, status, attempts, result, error, …}` |

The frontend (`assets/script.js`) calls `pollBackgroundTask(taskId)` after an
enqueue so the progress modal reflects real completion.

---

## 7. Long-request protection

* `sync_wialon` → background task (inline fallback).
* `bulk_fetch_messages` / `bulk_push_activity` (SSE) → hard wall-clock budget
  `bulk_sse_max_seconds` (default 90 s). When exceeded, the stream emits
  `{type:"done", partial:true, queued:N}` and hands the rest to the worker.

---

## 8. Query & index changes (schema v11)

New indexes (all idempotent; see `migrations/009_lifecycle.sql`):

| Table | Index | Backs |
|---|---|---|
| `message_log` | `idx_ml_vehicle_time (vehicle_id, wialon_msg_time)` | per-vehicle high-water mark (`MAX(wialon_msg_time)`) |
| `emergency_queue` | `idx_eq_event_created (emergency_event_id, created_at)` | reaper + retention join |
| `job_runs` | `idx_jobruns_started (started_at)` | run-history retention |
| `automation_runs` | `idx_ar_started (started_at)` | run-history retention |
| `audit_log` | `idx_al_created (created_at)` | audit retention |
| `webhook_events` | `idx_wh_created (created_at)` | webhook retention |
| `rate_limits` | `idx_rl_window (window_start)` | rate-limit retention |

Code changes:

* `GpsMonitor::refresh()` — one grouped query instead of `MAX()` per vehicle, and
  a single chunked multi-row upsert instead of one auto-committed statement per
  vehicle (measured: ~31.5 s → ~0.23 s for a 405-vehicle fleet in this env).
* `Scheduler::jobPoll()` — one shared Wialon session (`FleetSync::pollMany`)
  instead of one login per vehicle.
* `Scheduler::jobRollup()` — range predicate instead of `DATE(created_at)=CURDATE()`.
* `Scheduler::jobRetention()` — delegates to the chunked engine.

---

## 9. Monitoring

* **Health page** (`health.php`) → *Data Lifecycle* card: background queue depth,
  active emergency events, emergency buffer rows, retention windows, last
  retention run (rows + duration), largest tables.
* **`retention_log`** table: one row per cycle (`ran_at`, `duration_ms`,
  `rows_deleted`, `dry_run`, `summary` JSON with per-step counts). Self-pruned by
  `audit_retention_days`.
* **JSON logs** (`logs/`): `retention.run`, `retention.step`,
  `retention.step_failed`, `bg.enqueue`, `bg.start`, `bg.retry`, `bg.failed`,
  `bg.continuation`, `emergency.reaped`, `frontend/backend` warnings.

---

## 10. Operating runbook

The scheduler (`cron.php` / `bin/worker.php`) is the normal driver. Its jobs now
include:

| Job | Interval | Action |
|---|---|---|
| `emergency_reap` | 300 s | close idle emergency events |
| `background_tasks` | 15 s | run queued imports/pushes |
| `retention` | 86400 s | full `Retention::runAll()` |
| `purge_delivered` | 3600 s | fast purge of LATRA-confirmed rows |

Manual equivalents:

```bash
# Full retention (dry run first in production)
php scripts/maintain.php --dry-run
php scripts/maintain.php

# Environment overrides (see MAINTAIN_* in the script header)
MAINTAIN_STREAM_DAYS=14 MAINTAIN_AUDIT_DAYS=365 php scripts/maintain.php

# Reclaim physical disk after a large purge (InnoDB keeps file size until rebuild)
php scripts/reclaim.php --dry-run
php scripts/reclaim.php

# Housekeeping + DB retention (gzip/rotate logs, prune backups, then maintain)
scripts/cleanup.sh
```

`scripts/maintain.php` keeps its historical "stream window" semantics but now
routes everything through the chunked engine.

---

## 11. Deployment & migration

The durable migration hook is `Schema::ensure()`, which runs on every
authenticated request (guarded by `static $done`) via `Auth::requireLogin()`.
Index/DDL work is gated on `schema_version < Schema::VERSION`, so steady-state
requests pay nothing.

* **Upgrade path (recommended):** deploy the code. On the next authenticated
  request `Schema::ensure()` sets `schema_version = 11`, creates
  `background_tasks` / `retention_log`, adds the new indexes, and seeds the new
  config + jobs.
* **DB-side / CI path:** run `migrations/009_lifecycle.sql` (idempotent, targets
  the connection's current database — no hardcoded `USE`).
* **Fresh installs:** `database.sql` includes the new tables and the
  `idx_ml_vehicle_time` index; `Schema::ensure()` seeds the rest.

Apply the migration during a low-traffic window: on a large table, adding an
index is the only heavy DDL and completes in seconds at the reference volume.

### Pre-deploy checklist

1. Back up (see §12).
2. Run `php scripts/maintain.php --dry-run` and sanity-check the counts.
3. Deploy code (or run migration 009).
4. Ensure the worker/cron is running so retention + background jobs execute.
5. Watch the health page *Data Lifecycle* card and `retention_log`.

---

## 12. Backup & rollback

**Before enabling** (or before a first manual purge), take a logical backup of
the affected tables:

```bash
mysqldump --single-transaction --quick <db> \
  message_log message_log_archive push_log push_log_archive outbox \
  emergency_queue emergency_events validation_errors job_runs \
  | gzip > backups/pre-retention-$(date +%F-%H%M%S).sql.gz
```

Rollback options, in order of preference:

* **Config-only:** raise the retention windows (`UPDATE config …`) or set
  `archive_hot_messages = 0` to stop archiving, then re-run. No code change.
* **Re-import:** restore the backed-up tables. Restoring only *some* tables can
  re-create rows that were legitimately pruned, but never violates FK integrity
  (references are to `vehicles`, which is untouched).
* **Code:** `git revert` the change set. The engine is additive; the previous
  behaviour (immortal rows) resumes without schema changes. New tables/indexes
  can be left in place harmlessly.

Nothing the engine deletes is referenced by `vehicles`, `activity_*`, or
`automation_buckets`, so a partial restore cannot corrupt business data.

---

## 13. Verification (what to check)

```bash
# 1. All touched files parse
php -l includes/Retention.php includes/BackgroundTasks.php includes/Scheduler.php \
        includes/Schema.php includes/FleetSync.php includes/GpsMonitor.php \
        includes/EmergencyHandler.php api.php health.php scripts/maintain.php \
        scripts/reclaim.php

# 2. Schema is at v11 with the new objects
mysql <db> -e "SELECT config_value FROM config WHERE config_key='schema_version';"
mysql <db> -e "SHOW TABLES LIKE 'background_tasks'; SHOW TABLES LIKE 'retention_log';"

# 3. Retention is a no-op right after a run (idempotent)
php scripts/maintain.php --dry-run

# 4. A single pass removes exactly the reported rows
php scripts/maintain.php

# 5. Backlog actually drained
mysql <db> -e "SELECT COUNT(*) FROM emergency_queue;"              # -> 0
mysql <db> -e "SELECT COUNT(*) FROM emergency_events WHERE status='active';"  # -> 0

# 6. Last cycle audit
mysql <db> -e "SELECT ran_at, duration_ms, rows_deleted, dry_run FROM retention_log ORDER BY id DESC LIMIT 5;"
```

Reference run (this repo's DB, data frozen at 2026-10-05..09):

```
message_log.delivered     ... rows   emergency_queue  2765 rows
emergency_events          reaped 6 active -> ended
message_log.stale_pending ... rows   retention_log    recorded
```

---

## 14. Failure modes & recovery

| Symptom | Cause | Recovery |
|---|---|---|
| A step reports `reason: time_budget` | more rows than fit the per-step budget | expected — the next cycle resumes where it stopped |
| `retention.step_failed` in logs | a step hit a SQL/permission error | the other steps still ran; fix and re-run (idempotent) |
| Background task stuck `running` | worker died mid-task | `reapStale()` (in `runDue`) returns it to `pending`; retried automatically |
| Background task `failed` | exceeded `max_attempts` | inspect `error`, fix root cause, `enqueue` again; a `background_task_failed` webhook fires |
| Disk still full after a purge | InnoDB freed pages but kept file size | `php scripts/reclaim.php` (OPTIMIZE TABLE) in a low-traffic window |
| Sync button "does nothing" after handoff | no worker ticking | start cron/worker, or the API falls back inline when `last_tick.txt` is stale |

---

## 15. Tuning guide

* **Database still growing?** Lower `stale_pending_retention_days` (default 7)
  and `rejected_retention_days`. These are the biggest non-actionable buckets.
* **Need longer audit history?** Raise `audit_retention_days` (365) /
  `job_run_retention_days` (60). They are low-volume.
* **Faster steady-state pruning?** Lower `delivered_retention_hours` (24) — sent
  rows are the safest to drop; their receipt lives in `push_log`.
* **Slower worker tick?** Lower `background_task_batch`, raise
  `background_task_seconds`.
* **Longer SSE sessions?** Raise `bulk_sse_max_seconds`, but keep it under the
  gateway timeout (Cloudflare default 100 s).
* **Emergency churn?** Tune `emergency_idle_seconds` (900) to close idle events
  sooner.
