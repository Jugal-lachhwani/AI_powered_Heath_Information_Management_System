# Running locally

Two processes, two terminals, both from the repository root.

## 1. AI backend (FastAPI, port 8001)

```bash
cd AI_backend
source .venv/bin/activate
uvicorn app.main:app --host 127.0.0.1 --port 8001 --reload --reload-dir app
```

First time only: `python3 -m venv .venv && pip install -r requirements.txt && cp .env.example .env`, then add your keys.
Keep `SAMPLE_RATE=24000` so it matches the browser client.

## 2. PHP workspace (port 9000)

```bash
PHP_CLI_SERVER_WORKERS=4 php -c web/php.ini -S 127.0.0.1:9000 -t web/public
```

Only `web/public` is served; includes, config and the SQLite database stay outside the web root.
Multiple workers are needed because emailing a PDF makes a request back to the same server.

## 3. Open it

<http://127.0.0.1:9000>

- Register the first account: it becomes admin automatically.
- Signing in lands on the intake dashboard.
- **Voice consultation** in the navigation opens the front desk (`/patients/select.php`); pick a patient to start a visit.

## Demo data without API keys

```bash
php web/scripts/seed_demo.php        # on a fresh database
```

Sign in as `anita.sharma@example.com` / `demo1234`.

## URL map

| Area | Path |
|---|---|
| Sign in / register / sign out | `/auth/login.php`, `/auth/register.php`, `/auth/logout.php` |
| Front desk, patient timeline | `/patients/select.php`, `/patients/timeline.php?patient_id=` |
| Live consultation, printable record | `/consultation/workspace.php`, `/consultation/print.php?visit_id=` |
| AI intake calls | `/intake/dashboard.php`, `calls`, `call-monitor`, `call-review`, `queue`, `request-call`, `analytics` |
| Admin | `/admin/agent-config.php`, `/admin/telephony.php` |
| JSON endpoints | `/api/consultation/*`, `/api/intake/*`, `/api/admin/*` |
