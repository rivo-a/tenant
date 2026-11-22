# Rotating the SMS.UG API key

The reminder page reads the token from `.env` on every request, so rotation needs no code change and no
server restart. Renaming the key does break sending: `public/remind.php` looks for exactly
`SMS_UG_API_KEY`.

## 1. Create the new token

1. Sign in to https://app.sms.ug.
2. Open the API / developer section of the dashboard.
3. Create a new token (or revoke the old one and create a fresh one). Copy it immediately — dashboards
   usually show it once.
4. Note whether the dashboard shows a sender ID or default title you must keep; the app sends
   `Rent Reminder` as the title.

## 2. Put it in `.env`

File: `.env` at the repository root (the same directory as `composer.json`). It is git-ignored; keep it
that way.

```
SMS_UG_API_KEY=your-new-token
```

Rules that matter:

- No quotes, no `export`, no trailing spaces, one line.
- Keep the key name exactly `SMS_UG_API_KEY` — `public/remind.php:38` reads `$_ENV['SMS_UG_API_KEY']`.
- `.env` is loaded by `core/bootstrap.php` via `Dotenv::createImmutable(BASE_PATH)->safeLoad()`, so a
  malformed line is skipped silently rather than fataling; if sending suddenly reports "not configured",
  that line is the first thing to check.

## 3. Verify before revoking the old token

Open **Remind**, pick a tenant, send to your own number first. What to look for:

- Success: an `sms_logs` row with status moving past `PENDING` and a provider message id.
- Failure: "SMS.UG rejected the API credentials" means the token is wrong or has not propagated.
- Failure with a balance error means the account is out of credit, not a key problem.

Only after a successful send should the old token be revoked, so sending never goes dark in between.

## 4. Command line check

Reads the key from `.env`; nothing is printed:

```
php -r 'require "core/bootstrap.php";
$key = $_ENV["SMS_UG_API_KEY"] ?? "";
echo "key present: " . ($key !== "" ? "yes (" . strlen($key) . " chars)" : "NO") . PHP_EOL;'
```

## 5. Habits

- Rotate when staff with access leave, and periodically otherwise.
- Never paste the token into a commit, a ticket, the database, or a screenshot.
- `.env.example` lists the key name with an empty value; it is the only place the name is documented.
- A blank value is safe: reminders fail with a clear message rather than sending with no authorisation.
