# LATRA-Wialon Integration Hub

GPS Fleet Compliance & Automated VTS Submission Platform for Tanzania's Land Transport Regulatory Authority (LATRA).

Polls GPS/telemetry from Wialon, classifies 24 activity types, and submits LATRA-compliant payloads — fully automated with a bucket-based scheduler, durable outbox, and real-time dashboard.

---

## Features

- **24 LATRA Activities** — Movement, Engine ON/OFF, Speeding, Harsh Driving, Panic, Battery, Power, Tampering, Accident, Fuel, iButton, Geofence, and more
- **Bucket Automation** — One bucket per activity, configurable cadence (5 min default), per-vehicle assignment, on-demand runs
- **Master Kill Switch** — Toggle all automations on/off with a single button
- **Durable Outbox** — Retry with exponential backoff, circuit breaker on LATRA, idempotent submissions
- **Event Detection** — Real-time classification from Wialon parameters: g-force, ignition, battery, power, SOS
- **Emergency Mode** — 1 Hz polling during active emergencies with event tracking
- **Device Health** — Online/offline status, battery voltage, external power, tamper detection
- **Fleet Sync** — Auto-sync vehicles from Wialon, IMEI backfill
- **GPS Validation** — Rejects invalid coordinates, timestamps, HDOP/RSSI ranges before submission
- **Security** — Argon2id passwords, CSRF, rate limiting, account lockout, AES-256 encrypted secrets, audit log
- **Dashboard** — Real-time KPIs, push success rates, queue depth, device health overview

---

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Language | PHP 8.1+ (vanilla, no framework) |
| Database | MySQL 10.5+ / MariaDB 10.6+ |
| Web Server | Apache 2.4 with mod_php |
| Frontend | Vanilla JS, CSS, SweetAlert2, Leaflet maps |
| Scheduler | Cron → `cron.php` (minute-level) |
| External APIs | Wialon Remote API, LATRA VTS Annexure-1 |

No Composer, no Node.js, no build step. Namespaced `src/` classes use the project's small PSR-4-compatible autoloader.

## Backend layering direction

New backend work is being separated incrementally into domain rules, application use cases, infrastructure (PDO repositories and external API adapters), and HTTP/CLI entry points. Existing root pages, `api.php`, cron scripts, and classes under `includes/` remain in place and keep their current contracts while each flow is migrated and regression-tested. This avoids a framework change or a broad move that could break deployments.

---

## Quick Start

```bash
# 1. Clone into web root
cd /opt/lampp/htdocs
git clone <repo-url> latra-wialon-integration
cd latra-wialon-integration

# 2. Configure
cp .env.example .env
# Edit .env with WIALON_TOKEN, LATRA_API_URL, LATRA_USERNAME, LATRA_PASSWORD

# 3. Create database
mysql -u root -e "CREATE DATABASE IF NOT EXISTS latra_wialon CHARACTER SET utf8mb4;"

# 4. Create admin user (sets role=admin, generates APP_KEY, creates tables)
php scripts/install.php admin your_password

# 5. Open browser
# http://localhost/latra-wialon-integration/login.php
```

Schema, tables, and migrations auto-create on first run.

**Cron** (minimal setup):

```bash
* * * * * /usr/bin/php /path/to/latra/cron.php >> /path/to/latra/logs/cron.log 2>&1
0 3 * * * /bin/bash /path/to/latra/scripts/backup.sh
15 4 * * * /bin/bash /path/to/latra/scripts/cleanup.sh >> /path/to/latra/logs/cleanup.log 2>&1
```

`cleanup.sh` enforces retention: streaming Wialon→LATRA data 14 days (env `MAINTAIN_STREAM_DAYS`), automation 60d, audit 365d, AI 180d; rotates `error.log`/`cron.log` at 20 MB; gzips old backups. Constant data (vehicles, users, jobs, buckets, config, KPIs) is never deleted.

For detailed instructions see **[SETUP_GUIDE.md](SETUP_GUIDE.md)**.

---

## Project Structure

```
latra-wialon-integration/
├── config.php              # Environment loader, autoloader
├── src/                    # Namespaced domain and application code
│   ├── Domain/            # Collection, delivery and ingestion policies
│   ├── Application/       # Use cases and repository/effect ports
│   └── Infrastructure/    # PDO persistence, Wialon and legacy adapters
├── .env                    # Secrets (not in git)
├── cron.php                # Scheduler entry point
├── database.sql            # Full schema dump
│
├── index.php               # Dashboard
├── login.php               # Authentication
├── vehicles.php            # Fleet list
├── vehicle_detail.php      # Per-vehicle detail + history
├── activities.php          # 24 LATRA activity definitions
├── automation.php          # Bucket management + master toggle
├── run_logs.php            # Run history
├── push_log.php            # LATRA submission audit
├── latra_audit.php         # Compliance audit view
├── health.php              # Device health monitoring
├── settings.php            # System configuration
├── api.php                 # JSON API endpoints
│
├── includes/               # Existing application, integration, and storage classes
│   ├── autoload.php        # PSR-4-compatible src/ loader and legacy class loader
│   ├── Database.php        # PDO singleton, auto-reconnect
│   ├── Auth.php            # Sessions, CSRF, rate limiting
│   ├── WialonAPI.php       # Wialon Remote API client
│   ├── LatraAdapter.php    # LATRA HTTP client + circuit breaker
│   ├── LatraMapper.php     # Wialon → LATRA payload builder
│   ├── IngestPipeline.php  # Ingest → validate → store → detect
│   ├── BucketRunner.php    # Per-bucket vehicle processor
│   ├── EventDetector.php   # Activity classification (24 types)
│   ├── Outbox.php          # Durable retry queue
│   ├── Scheduler.php       # Job scheduler with locking
│   ├── FleetSync.php       # Wialon fleet synchronization
│   └── ...                 # 20+ service classes
│
├── scripts/                # Setup & maintenance
├── bin/                    # Worker daemon
├── assets/                 # CSS + JS
├── logs/                   # Runtime logs
└── docs/                   # Architecture documentation
```

---

## Data Flow

```
GPS Device → Wialon Server → [API poll] → BucketRunner
    → EventDetector (classify activity 1-24)
    → message_log (store)
    → Outbox (enqueue)
    → LatraAdapter (HTTP POST)
    → LATRA Server (vts.latra.go.tz:8090)
    → Response: accepted / rejected / retry
```

---

## Automation Architecture

| Component | Description |
|-----------|-------------|
| **Buckets** | 24 buckets (one per LATRA activity), each with configurable interval, cooldown, and vehicle assignment |
| **Scheduler** | `cron.php` runs every minute, processes buckets first, then jobs |
| **Outbox** | Durable queue with `pending → in_flight → sent/failed`, exponential backoff, max 8 retries |
| **Circuit Breaker** | Pauses LATRA submissions for 60s after 5 consecutive connection failures |
| **Master Toggle** | Single switch to enable/disable all buckets and scheduler jobs |

---

## Configuration

All config via `.env` file:

| Variable | Required | Description |
|----------|----------|-------------|
| `DB_HOST` | Yes | MySQL host (default: `127.0.0.1`) |
| `DB_NAME` | Yes | Database name (default: `latra_wialon`) |
| `DB_USER` | Yes | MySQL user (default: `root`) |
| `DB_PASS` | No | MySQL password |
| `WIALON_BASE_URL` | Yes | `https://hst-api.wialon.com/wialon/ajax.html` |
| `WIALON_TOKEN` | Yes | Wialon API token |
| `LATRA_API_URL` | Yes | `http://vts.latra.go.tz:8090/data-integration/integration/gps` |
| `LATRA_USERNAME` | Yes | LATRA API username |
| `LATRA_PASSWORD` | Yes | LATRA API password |
| `APP_KEY` | No | Encryption key (auto-generated if empty) |
| `FORCE_HTTPS` | No | `on` to enforce HTTPS |
| `APP_ENV` | No | `production` (default) or `development` |
| `OPENAI_API_KEY` | No | AI Assistant key (any OpenAI-compatible endpoint; empty = assistant disabled) |
| `AI_BASE_URL` | No | Chat-completions URL (OpenAI / OpenRouter / Azure). Default: OpenAI |
| `OPENAI_MODEL` | No | Model name (e.g. `openai/gpt-4o` on OpenRouter) |
| `AI_RATE_LIMIT` | No | Max assistant requests per user/IP per minute (`20`) |
| `AI_MAX_TOKENS` | No | Max answer tokens (`900`) |
| `AI_TIMEOUT` | No | Per-request timeout in seconds (`45`) |
| `AI_AUDIT` | No | `on`/`off` — write `ai.chat` audit rows (`on`) |

Runtime tunables in the `config` database table (editable via Settings page).

---

## LATRA Activities

| ID | Activity | ID | Activity |
|----|----------|----|----------|
| 1 | Movement/Logging | 13 | Panic Button (Passenger) |
| 2 | Engine ON | 14 | Device Tampering |
| 3 | Engine OFF | 15 | Black Box Data Logging |
| 4 | Speeding | 16 | Fuel Data Report |
| 5 | Harsh Braking | 17 | Invalid iButton Scan |
| 6 | Harsh Turning | 18 | Engine Start |
| 7 | Harsh Acceleration | 19 | Engine Stop |
| 8 | Panic Button (Driver) | 20 | Enter Boundary |
| 9 | Internal Battery Low | 21 | Leave Boundary |
| 10 | External Power Disconnected | 22 | Enter Checkpoint |
| 11 | Excessive Idle | 23 | Leave Checkpoint |
| 12 | Accident | 24 | iButton Scan (Regular) |

---

## Requirements

- PHP 8.1+ with extensions: `curl`, `pdo_mysql`, `mbstring`, `openssl`, `session`
- MySQL 10.5+ or MariaDB 10.6+
- Apache 2.4 with `mod_php`
- Outbound access to `hst-api.wialon.com` (HTTPS) and `vts.latra.go.tz:8090` (HTTP)

---

## License

Proprietary — for authorized LATRA compliance projects only.
