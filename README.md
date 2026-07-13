# PHP Tasks — Email Inbox, Inquiries API, Inquiry Tracker

Vanilla PHP + MySQL. Shared config lives in `config/` (one place for DB credentials).

## Setup

```bash
# 1) Create DB + tables
mysql -u task -p'TaskPass123!' < sql/schema.sql

# 2) Credentials from .env
cp .env.example .env
# edit .env — set IMAP_USERNAME / IMAP_PASSWORD (Gmail App Password)

# 3) Seed 100k inquiries (Task 2)
php task2/seed.php --truncate --count=100000
```

Default DB (local Homebrew MySQL reset for this project):

| Setting  | Value            |
|----------|------------------|
| Host     | 127.0.0.1        |
| Database | inquiry_tracker  |
| User     | task             |
| Password | TaskPass123!     |

## Task 1 — IMAP email processor

| File | Purpose |
|------|---------|
| `task1/fetch_emails.php` | CLI: fetch latest 20 mails, store in MySQL |
| `task1/list.php` | Web: list/search stored emails |
| `task1/demo_parse_and_store.php` | Offline MIME demo (multipart + encoded headers + attachment) |
| `task1/lib/ImapClient.php` | Pure-PHP IMAP over SSL (no `ext-imap` required) |
| `task1/lib/MailParser.php` | MIME decode, preview, attachment names |

```bash
# Without IMAP creds — prove parser + duplicate skip:
php task1/demo_parse_and_store.php
php task1/demo_parse_and_store.php   # second run = duplicate skipped

# With Gmail App Password in config.local.php:
php task1/fetch_emails.php

# List UI
php -S localhost:8081 -t task1
# open http://localhost:8081/list.php
```

### Cron note (safe every few minutes)

1. **Lockfile** — `fetch_emails.php` uses `flock(LOCK_EX|LOCK_NB)`. If the previous run is still going, the new cron exits immediately (no double-processing).
2. **Idempotent writes** — `INSERT IGNORE` on unique `message_id`, so re-fetching the same 20 messages is a no-op.
3. **Timeouts** — `set_time_limit(90)` + IMAP connect timeout ~25s so a hung mailbox cannot pile up PHP workers.
4. **Schedule** — e.g. `*/5 * * * * cd /path/to/Task && /usr/bin/php task1/fetch_emails.php >> storage/fetch.log 2>&1`
5. **Secrets** — keep IMAP app passwords in `config.local.php` (not committed); prefer a dedicated mailbox user with least privilege.

## Task 2 — Inquiries JSON REST API

```bash
php -S localhost:8090 -t task2/public task2/public/index.php
```

| Method | Path | Notes |
|--------|------|-------|
| GET | `/inquiries` | `search`, `status`, `page`, `per_page`, `sort`, `order` — all applied in SQL |
| POST | `/inquiries` | JSON body → `201` |
| PATCH | `/inquiries/{id}` | Partial update |

Error codes: `400` bad input, `404` missing row, `422` validation, `201` created.

```bash
curl 'http://localhost:8090/inquiries?status=new&page=1&per_page=5&sort=created_at&order=desc'
curl -X POST http://localhost:8090/inquiries -H 'Content-Type: application/json' \
  -d '{"customer_name":"Ada Lovelace","email":"ada@example.com","status":"new"}'
curl -X PATCH http://localhost:8090/inquiries/1 -H 'Content-Type: application/json' \
  -d '{"status":"closed"}'

php task2/benchmark.php
```

### Indexes (why they’re there)

- `idx_inquiries_status_created (status, created_at)` — hottest list pattern: filter status + sort by date.
- `idx_inquiries_created (created_at)` — unfiltered chronological lists / pagination.
- `idx_inquiries_email`, `idx_inquiries_customer_name` — lookups and prefix filters.
- `FULLTEXT ft_inquiries_search (customer_name, email, notes)` — keyword search via `MATCH … AGAINST` so we don’t rely on leading-wildcard `LIKE '%x%'` scans on 100k+ rows.

### Production note (what I’d change)

Add API auth (API keys or JWT) and never expose the DB port. Put a reverse proxy (nginx) in front with rate limiting per IP/key. Cache hot `GET /inquiries?status=…` pages briefly in Redis (invalidate on POST/PATCH). Wrap multi-step writes in transactions if you later add related tables (audit log, assignments). Prefer cursor/keyset pagination over large `OFFSET` for deep pages. Emit structured request logs and metrics (latency, 4xx/5xx). Move secrets to env vars / a secrets manager; enable TLS everywhere.

## Task 3 — Inquiry Tracker UI

```bash
php -S localhost:8082 -t task3
# http://localhost:8082/list.php
# http://localhost:8082/add.php
# http://localhost:8082/report.php
```

- Add form with server-side required fields + email validation
- List with search (name/email) and status filter
- Inline status update via AJAX (`update_status.php`) — no full reload
- Report: counts grouped by status

Task 2 and Task 3 share the same `inquiries` table and `config/database.php`.
