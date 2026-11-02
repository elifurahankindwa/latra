# LATRA-Wialon Integration Hub — Setup Guide

A step-by-step guide to deploy the LATRA GPS Compliance & Automated VTS Submission platform from scratch.

---

## 1. System Requirements

| Component | Requirement |
|-----------|-------------|
| **OS** | Linux (Ubuntu 20.04+, Debian 11+, CentOS 8+) |
| **PHP** | 8.1+ (8.1, 8.2, 8.3, 8.4, 8.5 tested) |
| **MySQL/MariaDB** | 10.5+ / MariaDB 10.6+ |
| **Web Server** | Apache 2.4 with `mod_php` (XAMPP works for dev) |
| **PHP Extensions** | `curl`, `pdo_mysql`, `mysqli`, `json`, `mbstring`, `openssl`, `session`, `hash` |
| **Disk** | ~100 MB for code + growing DB (each message ~1 KB) |
| **Network** | Outbound access to `hst-api.wialon.com` (HTTPS) and `vts.latra.go.tz:8090` (HTTP) |

### Verify PHP Extensions

```bash
php -m | grep -iE 'curl|pdo_mysql|mysqli|json|mbstring|openssl|session|hash'
```

All listed extensions must be present. If missing, install them:

```bash
# Ubuntu/Debian
sudo apt install php8.2-curl php8.2-mysql php8.2-mbstring php8.2-json php8.2-xml

# RHEL/CentOS
sudo yum install php-curl php-mysqlnd php-mbstring php-json php-xml

# For XAMPP (already bundled)
# All extensions are included by default
```

### Verify Argon2 Password Hashing

```bash
php -r "echo function_exists('password_hash') && defined('PASSWORD_ARGON2ID') ? 'OK' : 'MISSING';"
```

Must print `OK`. If not, install `php-sodium` or recompile PHP with `--with-password-argon2`.

---

## 2. Install the Code

### Option A: Copy from archive

```bash
cd /opt/lampp/htdocs
cp -r /path/to/latra-wialon-integration ./
cd latra-wialon-integration
```

### Option B: Clone from git

```bash
cd /opt/lampp/htdocs
git clone <repo-url> latra-wialon-integration
cd latra-wialon-integration
```

### Set file permissions

```bash
# Web server user (www-data for Apache, nobody for XAMPP)
chown -R mr-pinks:mr-pinks /opt/lampp/htdocs/latra-wialon-integration
chmod -R 755 /opt/lampp/htdocs/latra-wialon-integration

# Ensure logs directory is writable
mkdir -p logs tmp
chmod 777 logs tmp
```

---

## 3. Configure Environment

### Create the `.env` file

```bash
cp .env.example .env
```

Edit `.env` with your values:

```env
# Database
DB_HOST=127.0.0.1
DB_NAME=latra_wialon
DB_USER=root
DB_PASS=
DB_CHARSET=utf8mb4

# Wialon API
# Get token from: https://hst-api.wialon.com → User Settings → Tokens
WIALON_BASE_URL=https://hst-api.wialon.com/wialon/ajax.html
WIALON_TOKEN=YOUR_WIALON_TOKEN_HERE

# LATRA API (provided by LATRA during registration)
LATRA_API_URL=http://vts.latra.go.tz:8090/data-integration/integration/gps
LATRA_USERNAME=YOUR_LATRA_USERNAME
LATRA_PASSWORD=YOUR_LATRA_PASSWORD

# Optional
APP_ENV=production
FORCE_HTTPS=off
APP_KEY=$(openssl rand -hex 32)

# AI Assistant (server-side only — never share a real key)
# Any OpenAI-compatible endpoint works (OpenAI, OpenRouter, Azure).
OPENAI_API_KEY=YOUR_OPENAI_API_KEY
AI_BASE_URL=https://api.openai.com/v1/chat/completions
OPENAI_MODEL=openai/gpt-4o
AI_RATE_LIMIT=20
AI_MAX_TOKENS=900
AI_TIMEOUT=45
AI_AUDIT=on
```

### Important Notes

- **WIALON_TOKEN**: Generate at https://hst-api.wialon.com → User Settings → Access Tokens. Enable "Allow reading messages" and "Allow modifying objects".
- **LATRA credentials**: Provided by LATRA after your fleet is registered. Each fleet has its own username/password.
- **APP_KEY**: Generate a random key for encryption: `openssl rand -hex 32`
- **OPENAI_API_KEY / AI_BASE_URL**: The assistant talks to an OpenAI-compatible chat-completions API. For OpenRouter use `AI_BASE_URL=https://openrouter.ai/api/v1/chat/completions` and `OPENAI_MODEL=openai/gpt-4o`. If left empty (`OPENAI_API_KEY=`), the assistant reports "not configured" and stays greyed out.

---

## 4. Create the Database

### Option A: Auto-create (recommended)

The schema is created automatically on first access. Just ensure the database exists:

```bash
mysql -u root -e "CREATE DATABASE IF NOT EXISTS latra_wialon CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

### Option B: Import from SQL dump

```bash
mysql -u root latra_wialon < database.sql
```

All subsequent schema migrations run automatically via `Schema::ensure()` — no manual SQL needed.

---

## 5. Create the Admin User

Run `install.php` (generates APP_KEY, creates all tables, and sets the **admin** role):

```bash
cd /path/to/latra-wialon-integration
php scripts/install.php admin your_secure_password
```

If you omit the password, a random one is generated and printed once:

```bash
php scripts/install.php admin
# Output: Password: <random>
```

**Save this password immediately** — it won't be shown again.

To create non-admin users (operator, viewer, latra_*), use `setup_admin.php` (sets `operator` by default) then change the role in-app via the **Users** page (`/users.php`, admin-only) or directly:

```bash
php scripts/setup_admin.php operator1 pass123
mysql -u root latra_wialon -e "UPDATE app_users SET role='operator' WHERE username='operator1';"
```

### User Roles

| Role | Access |
|------|--------|
| `admin` | Full access — Users, Settings, buckets, all pages |
| `operator` | Dashboard, vehicles, push/run logs, settings (no Users page) |
| `viewer` | Read-only: dashboard, vehicles, push log, audit, health (no write UI) |
| `latra_user` / `latra_viewer` / `latra_inspector` / `latra_admin` | LATRA-specific restricted scopes (configured via `/users.php`) |

---

## 6. Start the Web Server

### XAMPP

```bash
sudo /opt/lampp/lampp start
```

Access at: **http://localhost/latra-wialon-integration/login.php**

### Standalone Apache

```bash
sudo systemctl start apache2
sudo systemctl enable apache2
```

Ensure `mod_php` and `mod_rewrite` are enabled:

```bash
sudo a2enmod php8.2 rewrite
sudo systemctl restart apache2
```

### Verify

```bash
curl -s -o /dev/null -w "%{http_code}" http://localhost/latra-wialon-integration/login.php
# Should return: 200
```

---

## 7. Initial Login & Verification

1. Open **http://localhost/latra-wialon-integration/login.php**
2. Log in with the admin credentials from Step 5
3. You'll land on the **Dashboard** showing fleet status

### Verify API Connectivity

Run these from the project root:

```bash
# Test Wialon connection
php -r '
require_once "config.php";
require_once "includes/Database.php";
require_once "includes/Vault.php";
require_once "includes/WialonAPI.php";
$w = new WialonAPI(WIALON_BASE_URL, WIALON_TOKEN);
echo $w->login() ? "Wialon: CONNECTED\n" : "Wialon: FAILED\n";
'

# Test LATRA connection
php -r '
require_once "config.php";
require_once "includes/Database.php";
require_once "includes/Vault.php";
require_once "includes/Logger.php";
require_once "includes/LatraAdapter.php";
$result = LatraAdapter::submit(["vehicle_reg_no" => "TEST", "type" => "poi", "imei" => "0000", "items" => []], "test-connectivity");
echo "LATRA HTTP: " . $result["httpCode"] . "\n";
echo "LATRA Response: " . substr($result["response"], 0, 200) . "\n";
'
```

---

## 8. Sync Fleet from Wialon

Sync all Wialon units into the local database:

```bash
cd /opt/lampp/htdocs/latra-wialon-integration
php -r '
require_once "config.php";
require_once "includes/Database.php";
require_once "includes/Schema.php";
require_once "includes/Vault.php";
require_once "includes/Logger.php";
require_once "includes/WialonAPI.php";
require_once "includes/LatraMapper.php";
require_once "includes/FleetSync.php";
Schema::ensure();
$result = FleetSync::syncFleet();
echo "Synced: " . json_encode($result) . "\n";
'
```

This creates `vehicles` rows for each Wialon unit. You can also trigger sync from the web UI at **Settings > Fleet Sync**.

### Map Vehicle Registration Numbers

Vehicles must have their **Tanzania registration number** (e.g., `T339DZT`) correctly set in Wialon as the unit name. If Wialon uses a different naming convention, the system tries to extract it from custom fields (`reg_no`, `registration`, `plate`, `plate_no`, `vehicle_reg`).

Check mapping:

```bash
mysql -u root latra_wialon -e "SELECT id, vehicle_reg_no, wialon_unit_id, imei, is_active FROM vehicles ORDER BY vehicle_reg_no LIMIT 20;"
```

---

## 9. Configure Automation Buckets

Automation buckets define **which activities to push to LATRA** and **how often**. Each bucket handles one LATRA activity type (1–24).

> **Movement rule (enforced in code, not config):** Activity 1 (Movement/Logging) is sent through interval-driven buckets at the configured three-minute cadence. It is excluded from the real-time event engine so movement is not submitted twice. Activity 1 telemetry remains stored in `message_log` and is also eligible for LATRA submission when an enabled movement bucket includes the vehicle.

### Bucket Configuration

| Field | Description | Default |
|-------|-------------|---------|
| `bucket_name` | Human-readable name | — |
| `activity_id` | LATRA activity type (1–24) | — |
| `interval_seconds` | Minimum seconds between runs | 300 (5 min) |
| `cooldown_seconds` | Pause after consecutive failures | 30 |
| `max_items_per_run` | Max vehicles to process per run | 100 |
| `max_run_seconds` | Hard timeout per run | 300 |
| `is_enabled` | 1=active, 0=paused | 1 |

### Create Buckets for All 24 Activities

```sql
-- Connect to the database
mysql -u root latra_wialon

-- Create one bucket per LATRA activity
INSERT INTO automation_buckets (bucket_name, activity_id, interval_seconds, cooldown_seconds, is_enabled, max_items_per_run, max_run_seconds, claim_lease_seconds)
VALUES
('movement', 1, 300, 30, 1, 100, 300, 120),
('engine_on', 2, 300, 30, 1, 100, 300, 120),
('engine_off', 3, 300, 30, 1, 100, 300, 120),
('speeding', 4, 300, 30, 1, 100, 300, 120),
('harsh_braking', 5, 300, 30, 1, 100, 300, 120),
('harsh_turning', 6, 300, 30, 1, 100, 300, 120),
('harsh_acceleration', 7, 300, 30, 1, 100, 300, 120),
('panic_driver', 8, 300, 30, 1, 100, 300, 120),
('battery_low', 9, 300, 30, 1, 100, 300, 120),
('power_disconnected', 10, 300, 30, 1, 100, 300, 120),
('excessive_idle', 11, 300, 30, 1, 100, 300, 120),
('accident', 12, 300, 30, 1, 100, 300, 120),
('panic_passenger', 13, 300, 30, 1, 100, 300, 120),
('tampering', 14, 300, 30, 1, 100, 300, 120),
('black_box', 15, 300, 30, 1, 100, 300, 120),
('fuel_report', 16, 300, 30, 1, 100, 300, 120),
('invalid_scan', 17, 300, 30, 1, 100, 300, 120),
('engine_start', 18, 300, 30, 1, 100, 300, 120),
('engine_stop', 19, 300, 30, 1, 100, 300, 120),
('enter_boundary', 20, 300, 30, 1, 100, 300, 120),
('leave_boundary', 21, 300, 30, 1, 100, 300, 120),
('enter_checkpoint', 22, 300, 30, 1, 100, 300, 120),
('leave_checkpoint', 23, 300, 30, 1, 100, 300, 120),
('ibutton_regular', 24, 300, 30, 1, 100, 300, 120);
```

### Assign Vehicles to Buckets

Each vehicle must be added to the buckets it should use:

```sql
-- Add vehicle (id=1 example) to ALL buckets
INSERT IGNORE INTO bucket_vehicles (bucket_id, vehicle_id)
SELECT b.id, 1 FROM automation_buckets b;

-- Or add to specific buckets only
INSERT IGNORE INTO bucket_vehicles (bucket_id, vehicle_id) VALUES
(1, 1),  -- movement bucket for vehicle 1
(9, 1);  -- battery_low bucket for vehicle 1
```

### Enable Activities for Vehicles

Each vehicle needs activities enabled in `activity_mappings`:
Speeding (activity 4) is enabled automatically for existing and newly synced
vehicles so detected overspeed events can be reported in real time. You can
disable it per vehicle from **Activity Mapping** if needed.

```sql
-- Enable all 24 activities for vehicle id=1
INSERT IGNORE INTO activity_mappings (vehicle_id, activity_id, is_enabled)
SELECT 1, seq.n, 1 FROM (
  SELECT 1 n UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6
  UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION SELECT 10 UNION SELECT 11 UNION SELECT 12
  UNION SELECT 13 UNION SELECT 14 UNION SELECT 15 UNION SELECT 16 UNION SELECT 17 UNION SELECT 18
  UNION SELECT 19 UNION SELECT 20 UNION SELECT 21 UNION SELECT 22 UNION SELECT 23 UNION SELECT 24
) seq;
```

### Manage Buckets via Web UI

Navigate to **Automation** page to:
- View all buckets and their status
- Enable/disable individual buckets
- Trigger on-demand runs
- View run history and error logs

---

## 10. Set Up the Scheduler

For low-latency event detection (including speeding), use the persistent worker.
It polls Wialon every 2–5 seconds and runs the event engine automatically:

```bash
sudo cp scripts/latra-worker.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now latra-worker.service
sudo systemctl status latra-worker.service
```

The included service unit assumes this installation path and `/opt/lampp/bin/php`.
Adjust those paths if your deployment differs.

If a persistent worker is unavailable, cron is a fallback with minute-level
polling; it does not provide the same event latency:

### Install the Crontab Fallback

```bash
crontab -e

# Scheduler fallback (every minute; use instead of the persistent worker)
* * * * * /usr/bin/php /path/to/latra/cron.php >> /path/to/latra/logs/cron.log 2>&1

# Nightly backup (03:00) — edit scripts/backup.sh path if needed
0 3 * * * /bin/bash /path/to/latra/scripts/backup.sh

# Daily auto-clean: logs, backups, DB retention (04:15)
15 4 * * * /bin/bash /path/to/latra/scripts/cleanup.sh >> /path/to/latra/logs/cleanup.log 2>&1
```

> **Tip (cPanel):** In Terminal run `which php` to get your PHP path (commonly `/usr/local/bin/php`). Replace `/path/to/latra/` with your actual project directory.

### Verify the Scheduler

```bash
# Check worker service (low-latency mode)
systemctl status latra-worker.service

# Or check cron fallback
ps aux | grep cron

# Check recent scheduler logs
tail -5 logs/cron.log

# Check scheduler heartbeat
cat logs/last_tick.txt
```

### Cron Jobs Managed by the Scheduler

Once cron.php is running, these jobs are managed automatically:

| Job | Interval | Description |
|-----|----------|-------------|
| `flush_outbox` | 30s | Push pending items to LATRA |
| `reconcile` | 60s | Crash recovery — reset stuck jobs |
| `emergency_poll` | 1s | High-frequency poll during emergencies |
| `poll_messages` | 180s | Normal GPS message polling |
| `offline_sweep` | 300s | Detect offline devices |
| `fleet_sync` | Daily | Sync fleet from Wialon |
| `backup` | Daily | SQL dump to `backups/` (via cron `backup.sh`) |
| `retention` | Daily | DB + log retention (via cron `cleanup.sh` + `maintain.php`) |

### Disable `poll_messages` (Recommended)

If using bucket-based automation, disable the old `poll_messages` job to avoid duplicate processing:

```sql
UPDATE jobs SET is_enabled = 0 WHERE job_key = 'poll_messages';
```

---

## 11. How the Data Flows

```
Wialon GPS Device
      │
      ▼
  Wialon Server (hst-api.wialon.com)
      │
      ▼ (API poll every 5 min)
  BucketRunner ──► EventDetector (classify activity 1–24)
      │                    │
      │                    ▼
      │             message_log (store + latra_payload)
      │                    │
      │                    ▼
      │             Outbox (enqueue if activity has enabled bucket)
      │                    │
      ▼                    ▼
  LatraAdapter ◄── Outbox::claimBatch (batch push)
      │
      ▼ (HTTP POST)
  LATRA Server (vts.latra.go.tz:8090)
      │
      ▼
  Response: {"status":"1"} (accepted) or {"status":"0","message":"..."} (rejected)
```

### Activity Classification

The `EventDetector` examines each Wialon message and assigns one of 24 LATRA activity types:

| ID | Activity | Trigger |
|----|----------|---------|
| 1 | Movement/Logging | Default — regular GPS position |
| 2 | Engine ON | Ignition flag transitions off→on |
| 3 | Engine OFF | Ignition flag transitions on→off |
| 4 | Speeding | Speed exceeds configured limit |
| 5 | Harsh Braking | Negative g-force threshold |
| 6 | Harsh Turning | Lateral g-force threshold |
| 7 | Harsh Acceleration | Positive g-force threshold |
| 8 | Panic (Driver) | Panic/SOS button press |
| 9 | Battery Low | Internal battery voltage low |
| 10 | Power Disconnected | External power lost |
| 11–24 | See LATRA spec | Various events |

### Payload Structure (sent to LATRA)

```json
{
  "vehicle_reg_no": "T339DZT",
  "type": "poi",
  "imei": "353691847702825",
  "items": [{
    "latitude": "-3.36909830",
    "longitude": "36.69535990",
    "altitude": "1446",
    "timestamp": "1788443782000",
    "horizontal_speed": "0.00",
    "bearing": "237.00",
    "satellite_count": "15",
    "HDOP": "0.60",
    "d2d3": "3",
    "RSSI": "5",
    "activity_id": "10",
    "addon_info": {
      "ext_power_voltage": "12.749",
      "int_battery_voltage": "0"
    },
    "idempotency_key": "v84-t1788443782-a10"
  }]
}
```

---

## 12. Web Pages

| Page | URL | Description |
|------|-----|-------------|
| Login | `/login.php` | Authentication |
| Dashboard | `/index.php` | Fleet overview, health, stats |
| Vehicles | `/vehicles.php` | Fleet list with status |
| Vehicle Detail | `/vehicle_detail.php?id=N` | Per-vehicle history, device health |
| Activities | `/activities.php` | 24 LATRA activity definitions |
| Automation | `/automation.php` | Bucket management, on-demand runs |
| Run Logs | `/run_logs.php` | Automation run history |
| Push Log | `/push_log.php` | LATRA submission audit trail |
| LATRA Audit | `/latra_audit.php` | Compliance audit view |
| Health | `/health.php` | Device health monitoring |
| Settings | `/settings.php` | System configuration (admin only) |

**AI Assistant** — a floating chat button on every authenticated page. It uses GPT-4o via an OpenAI-compatible endpoint (`OPENAI_API_KEY`, `AI_BASE_URL`, `OPENAI_MODEL`) and answers questions about vehicles, LATRA submissions, events, the scheduler, automation buckets, logs and compliance by calling a fixed set of read-only tools (no arbitrary SQL/shell/actions, no secrets exposed). Conversations are stored per user in `ai_conversations` / `ai_chat_log`, rate-limited per user/IP (`AI_RATE_LIMIT`) and audited (`ai.chat` in the audit log). The model is told to treat all DB/log/user content as untrusted data. See `.env` for the full set of `AI_*` options.

---

## 13. Security Features

- **Argon2id** password hashing (fallback to bcrypt)
- **CSRF tokens** on all forms
- **Rate limiting** per session and per IP
- **Account lockout** after 5 failed attempts (15-minute lock)
- **Session regeneration** on login
- **Encrypted secrets** — Wialon token, LATRA credentials stored AES-256-CBC in `vault` table
- **.htaccess** blocks dotfiles, logs, SQL, markdown, PDF files
- **Security headers**: X-Content-Type-Options, X-Frame-Options, CSP, HSTS
- **Prepared statements** everywhere (no SQL injection)
- **Audit log** — every admin action recorded
- **Circuit breaker** on LATRA — stops hammering after 5 consecutive failures (60s cooldown)

---

## 14. Backup & Recovery

### Manual Backup

```bash
bash scripts/backup.sh
# Creates dump + config snapshot in backups/
```

### Automated Backup

The nightly cron job (`0 3 * * *`) runs `scripts/backup.sh` automatically. Retention (14 days by default) is enforced by `scripts/cleanup.sh`.

### Restore

```bash
mysql -u root latra_wialon < backups/db-YYYYMMDD-HHMMSS.sql
```

---

## 15. Troubleshooting

### Scheduler not running

```bash
# Check cron
ps aux | grep cron
crontab -l

# Check cron.php log
tail -20 logs/cron.log

# Check scheduler heartbeat
cat logs/last_tick.txt

# Manual trigger
php cron.php
```

### LATRA rejecting payloads

```bash
# Check push log
mysql -u root latra_wialon -e "
  SELECT activity_id, http_code, SUBSTRING(response_body, 1, 200), created_at
  FROM push_log ORDER BY id DESC LIMIT 10;
"

# Common issues:
# status:0, "BID not found" → Vehicle not registered in LATRA
# status:0, "Duplicate" → Idempotency key collision (normal)
# Connection timeout → LATRA server unreachable
```

### Wialon Error 7 (Access Denied)

The vehicle's Wialon unit ID is not accessible with your token. Solutions:
1. Ensure the vehicle is shared with your Wialon account
2. Regenerate the token with proper permissions
3. The system auto-skips Error 7 units after first failure

### Circuit Breaker Open

```bash
# The circuit breaker opens after 5 consecutive LATRA failures
# It auto-resets after 60 seconds. Check the status:
mysql -u root latra_wialon -e "SELECT name, value FROM config WHERE name LIKE '%circuit%' OR name LIKE '%breaker%';"
```

### Database Connection Issues

```bash
# Test connection
mysql -u root -h 127.0.0.1 -P 3306 --skip-ssl latra_wialon -e "SELECT 1;"

# If socket not found, use TCP:
# Ensure .env has DB_HOST=127.0.0.1 (not localhost)
```

### View Error Logs

```bash
# PHP errors
tail -50 logs/error.log

# Cron execution log
tail -50 logs/cron.log

# Application events (structured JSON)
tail -20 logs/app-$(date +%Y-%m-%d).log
```

---

## 15.5 Auto-Clean (logs + database)

Run daily from cron (`15 4 * * *` → `scripts/cleanup.sh`). It performs retention for
streaming data (fetched from Wialon and posted to LATRA) while **never touching
constant/reference data** (vehicles, users, jobs, buckets, config, KPIs) and **never
deleting rows still queued for LATRA delivery** (referenced by `outbox`).

Since schema v11 every purge is delegated to the chunked `Retention` engine
(`includes/Retention.php`): bounded `DELETE … LIMIT` batches under a wall-clock
budget, per-step isolation, and a dry-run mode. See **`DATA_LIFECYCLE.md`** for the
full design, the auto-close of idle emergency events, the background task queue, and
operations. The in-app scheduler performs the same cycle; the table below lists the
`maintain.php` env overrides.

| Window | Env override | Default | What it prunes |
|---|---|---|---|
| Streaming data | `MAINTAIN_STREAM_DAYS` | `14` | `message_log` (sent + stale pending/failed not in the outbox), `push_log`, `wialon_events`, `outbox` (sent/rejected), `webhook_events`, `rate_limits` |
| Rejected/validation | `MAINTAIN_REJECTED_DAYS` | `30` | `message_log` (rejected), `validation_errors` |
| Automation history | `MAINTAIN_RUN_DAYS` | `60` | `automation_runs`, `job_runs` |
| Audit trail | `MAINTAIN_AUDIT_DAYS` | `365` | `audit_log` |
| AI chat history | `MAINTAIN_AI_DAYS` | `180` | `ai_conversations`, `ai_chat_log` |
| Log files | `CLEAN_LOG_DAYS` | `14` | `app-*.log`, `automation-*.log`, `*.old`, logs `.gz` |
| Log rotation | `CLEAN_ROTATE_MB` / `CLEAN_KEEP_GZ` | `20` MB / `5` | `error.log`, `cron.log`, `cleanup.log`, `login.log` (gzip + truncate) |
| Backups | `CLEAN_BACKUP_DAYS` / `CLEAN_GZIP_DAYS` | `14` / `3` | `backups/` retention + gzip old dumps |

Run once by hand: `bash scripts/cleanup.sh` (DB-down is not fatal — files are still cleaned).
Preview the DB portion without deleting: `php scripts/maintain.php --dry-run`.
After a large purge, reclaim physical disk with `php scripts/reclaim.php`.

### Login-attempt log (`logs/login.log`)

Every login attempt (success, failed, locked, rate-limited, empty) appends a JSON line to
`logs/login.log` with full device details: client IP + forwarded/real/CF IP chain, OS, browser
+ version, device class, raw User-Agent, Accept-Language, referer, origin, HTTPS/HTTP version,
method, timestamp, plus a client-side fingerprint (timezone, screen, languages, cores, memory,
connection type) when JavaScript is enabled. Optional reverse-DNS hostname of the client IP is
enabled with `LOGIN_LOG_DNS=on` in `.env`. Login events are also kept in `audit_log` (DB) so the
in-app audit page (`latra_audit.php`) still works. Treat `logs/login.log` as sensitive (already
blocked from web access via `.htaccess`).

---

## 16. Project Structure

```
latra-wialon-integration/
├── config.php                  # Environment loader, autoloader, constants
├── .env                        # Secrets (NOT in git)
├── .env.example                # Template
├── .htaccess                   # Apache security rules
├── cron.php                    # Scheduler entry point (runs every minute)
├── database.sql                # Full schema dump
│
├── index.php                   # Dashboard
├── login.php                   # Authentication
├── vehicles.php                # Fleet list
├── vehicle_detail.php          # Per-vehicle detail
├── activities.php              # 24 LATRA activity definitions
├── automation.php              # Bucket management
├── run_logs.php                # Automation run history
├── push_log.php                # LATRA submission audit
├── latra_audit.php             # Compliance audit view
├── health.php                  # Device health
├── settings.php                # System configuration
├── api.php                     # JSON API endpoints
│
├── includes/                   # Core service classes
│   ├── Database.php            # PDO wrapper (singleton, auto-reconnect)
│   ├── Auth.php                # Login, sessions, CSRF, rate limiting
│   ├── Schema.php              # Auto-migration (version 11)
│   ├── Vault.php               # AES-256-CBC encrypted secrets
│   ├── Logger.php              # Structured JSON-line logging
│   ├── WialonAPI.php           # Wialon Remote API client
│   ├── LatraAdapter.php        # LATRA HTTP client (circuit breaker)
│   ├── LatraMapper.php         # Wialon → LATRA payload builder
│   ├── IngestPipeline.php      # Message ingestion orchestrator
│   ├── BucketRunner.php        # Per-bucket vehicle processor
│   ├── EventDetector.php       # Activity classification (24 types)
│   ├── EmergencyHandler.php    # Emergency event state machine
│   ├── DeviceMonitor.php       # Device health tracking
│   ├── GpsMonitor.php          # GPS health tracking
│   ├── GpsValidator.php        # Payload validation
│   ├── Normaliser.php          # Wialon → canonical format
│   ├── ParamResolver.php       # Device parameter normalization
│   ├── Retention.php           # Chunked data-retention engine (schema v11)
│   ├── BackgroundTasks.php     # Background task queue (fleet sync / fetch / push)
│   ├── FleetSync.php           # Wialon fleet synchronization
│   ├── Outbox.php              # Durable message queue
│   ├── Scheduler.php           # Job scheduler (locks, ordering)
│   ├── Automation.php          # Bucket/activity helpers
│   ├── Webhooks.php            # Webhook delivery
│   ├── Layout.php              # HTML layout/templates
│   ├── DataCleaner.php         # Data sanitization
│   └── LatraMapper.php         # LATRA payload builder
│
├── scripts/
│   ├── setup_admin.php         # Create/reset admin user
│   ├── maintain.php            # DB retention (delegates to Retention engine)
│   ├── reclaim.php             # OPTIMIZE lifecycle tables (disk reclaim)
│   └── backup.sh               # Database backup script
│
├── bin/
│   ├── worker.php              # Long-running worker (alternative to cron)
│   ├── start-worker.sh         # Start worker daemon
│   └── stop-worker.sh          # Stop worker daemon
│
├── assets/
│   ├── style.css               # Main stylesheet
│   └── script.js               # Frontend JavaScript
│
├── logs/                       # Runtime logs (writable)
│   ├── cron.log                # Scheduler invocation log
│   ├── error.log               # PHP error log
│   ├── cron.pid                # Cron overlap watchdog
│   ├── last_tick.txt           # Scheduler heartbeat
│   └── app-YYYY-MM-DD.log      # Daily structured events
│
├── docs/                       # Documentation
│   ├── CRON_AND_SCHEDULER.md
│   ├── automation-strategy.md
│   └── automation-implementation.md
│
└── bruno/                      # API test collection
    └── latra-wialon-connectivity/
```

---

## 17. Quick Start Checklist

```
[ ] 1. Install PHP 8.1+, MySQL 10.5+, Apache with mod_php
[ ] 2. Verify PHP extensions: curl, pdo_mysql, mbstring, openssl, session
[ ] 3. Copy project to web root
[ ] 4. Create .env from .env.example
[ ] 5. Set WIALON_TOKEN, LATRA_API_URL, LATRA_USERNAME, LATRA_PASSWORD
[ ] 6. (Optional) Set OPENAI_API_KEY, AI_BASE_URL, OPENAI_MODEL for the AI Assistant
[ ] 7. Create database: CREATE DATABASE latra_wialon
[ ] 7. Run: php scripts/setup_admin.php admin <password>
[ ] 8. Start Apache/XAMPP
[ ] 9. Open http://localhost/latra-wialon-integration/login.php
[ ] 10. Log in and verify dashboard loads
[ ] 11. Run fleet sync (Settings or CLI)
[ ] 12. Create automation buckets for required activities
[ ] 13. Assign vehicles to buckets
[ ] 14. Enable all 24 activities per vehicle in activity_mappings
[ ] 15. Set up cron: * * * * * /usr/bin/php .../cron.php
[ ] 16. Verify cron runs: tail -f logs/cron.log
[ ] 17. Check push_log for LATRA submissions
[ ] 18. Verify data appears on LATRA dashboard
```

---

## 18. LATRA Activity Reference

All 24 activities per LATRA specification (IDD_GPS_Location_rev1.14):

| ID | Name | Requires addon_info |
|----|------|:---:|
| 1 | Movement/Logging | No |
| 2 | Engine ON | Yes |
| 3 | Engine OFF (Trip End) | Yes |
| 4 | Speeding | No |
| 5 | Harsh Braking | No |
| 6 | Harsh Turning | No |
| 7 | Harsh Acceleration | No |
| 8 | Panic Button (Driver) | No |
| 9 | Internal Battery Low | Yes |
| 10 | External Power Disconnected | Yes |
| 11 | Excessive Idle | No |
| 12 | Accident | No |
| 13 | Panic Button (Passenger) | No |
| 14 | Device Tampering | No |
| 15 | Black Box Data Logging | No |
| 16 | Fuel Data Report | Yes (fuel_info) |
| 17 | Invalid iButton Scan | Yes |
| 18 | Engine Start | No |
| 19 | Engine Stop | No |
| 20 | Enter Boundary | No |
| 21 | Leave Boundary | No |
| 22 | Enter Checkpoint | No |
| 23 | Leave Checkpoint | No |
| 24 | iButton Scan (Regular) | Yes |
