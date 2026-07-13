# PHP Tasks — Email Inbox, Inquiries API, Inquiry Tracker

Vanilla PHP + MySQL. Shared DB/IMAP config loads from **`.env`**.

---

## How to run (right now)

Do these once, from the project root:

```bash
cd /path/to/Task

# 1) Secrets
cp .env.example .env
# Edit .env — DB_* and (for real mail) IMAP_USERNAME / IMAP_PASSWORD

# 2) Database tables
mysql -u task -p'TaskPass123!' < sql/schema.sql

# 3) Seed 100k inquiries (Task 2 + Task 3)
php task2/seed.php --truncate --count=100000
```

Then open **three terminals** (or run one at a time):

### Task 1 — Emails

```bash
# Demo data (no Gmail needed)
php task1/demo_parse_and_store.php

# OR real inbox (needs IMAP_* in .env)
php task1/fetch_emails.php

# Start web UI — do NOT add anything after this line
php -S 127.0.0.1:8081 -t task1
```

Open: **http://127.0.0.1:8081/list.php**  
Search by sender or subject on that page.

### Task 2 — JSON REST API

```bash
php -S 127.0.0.1:8090 -t task2/public task2/public/index.php
```

Try:

```bash
curl 'http://127.0.0.1:8090/inquiries?status=new&page=1&per_page=5&sort=created_at&order=desc'

curl -X POST http://127.0.0.1:8090/inquiries \
  -H 'Content-Type: application/json' \
  -d '{"customer_name":"Ada Lovelace","email":"ada@example.com","status":"new"}'

curl -X PATCH http://127.0.0.1:8090/inquiries/1 \
  -H 'Content-Type: application/json' \
  -d '{"status":"closed"}'

php task2/benchmark.php
```

### Task 3 — Inquiry Tracker UI

```bash
php -S 127.0.0.1:8082 -t task3
```

| Page | URL |
|------|-----|
| List + search + AJAX status | http://127.0.0.1:8082/list.php |
| Add inquiry | http://127.0.0.1:8082/add.php |
| Report by status | http://127.0.0.1:8082/report.php |

> **Tip:** Never paste `# comments` on the same line as `php -S …` — PHP treats `#` as a router script and crashes.

---

## Default DB (.env)

| Setting  | Value            |
|----------|------------------|
| Host     | 127.0.0.1        |
| Database | `inquiry_tracker`|
| User     | `task`           |
| Password | `TaskPass123!`   |

Gmail IMAP: enable [2-Step Verification](https://myaccount.google.com/security), create an [App Password](https://myaccount.google.com/apppasswords), put it in `.env` as `IMAP_PASSWORD`.

---

## Task 1 details

| File | Purpose |
|------|---------|
| `task1/fetch_emails.php` | CLI: fetch latest 20 mails → MySQL |
| `task1/list.php` | Web: list/search stored emails |
| `task1/demo_parse_and_store.php` | Offline MIME demo (multipart + encoded headers + attachment) |
| `task1/lib/ImapClient.php` | Pure-PHP IMAP over SSL (no `ext-imap`) |
| `task1/lib/MailParser.php` | MIME decode, preview, attachment names |

Data flow: **IMAP (or demo) → MySQL `emails` → list.php**. The list page does not call Gmail live.

### Cron note (safe every few minutes)

1. **Lockfile** — `flock(LOCK_EX|LOCK_NB)` so a second cron exits if one is still running.
2. **Idempotent writes** — `INSERT IGNORE` on unique `message_id`.
3. **Timeouts** — `set_time_limit(90)` + short IMAP connect timeout.
4. **Schedule** — `*/5 * * * * cd /path/to/Task && /usr/bin/php task1/fetch_emails.php >> storage/fetch.log 2>&1`
5. **Secrets** — keep IMAP credentials in `.env` (not committed).

---

## Task 2 details

| Method | Path | Notes |
|--------|------|-------|
| GET | `/inquiries` | `search`, `status`, `page`, `per_page`, `sort`, `order` — all in SQL |
| POST | `/inquiries` | JSON body → `201` |
| PATCH | `/inquiries/{id}` | Partial update |

HTTP errors: `400` bad input, `404` missing, `422` validation.

### Indexes (why)

- `idx_inquiries_status_created (status, created_at)` — filter + sort (hot path)
- `idx_inquiries_created (created_at)` — unfiltered date lists
- `idx_inquiries_email`, `idx_inquiries_customer_name` — lookups
- `FULLTEXT ft_inquiries_search` — keyword search without `LIKE '%…%'` table scans

### Production note

Add API auth (API keys/JWT). Put nginx in front with rate limiting. Cache hot list queries in Redis; invalidate on POST/PATCH. Use transactions if you add related tables. Prefer keyset pagination over large `OFFSET`. Log latency/status codes. Keep secrets in env/secrets manager; use TLS.

---

## Task 3 details

- Add form with server-side required fields + valid email
- List with search (name/email) and status filter
- Inline status update via AJAX (`update_status.php`) — no full page reload
- Report: counts grouped by status

Task 2 and Task 3 share the same `inquiries` table and `config/database.php`.
