# Changelog

## 0.2.0

Pionne backend got a major security hardening pass. The SDK API is unchanged
but now talks to a stricter, more observable server:

- **2FA TOTP** for the dashboard account.
- **Audit log** of every sensitive action (1-year retention, visible in app).
- **Anomaly detection** — auto-alerts on volume spikes vs 7-day baseline,
  auto-pauses on critical spikes.
- **Server-side PII scrub** — defense-in-depth re-redaction of emails, JWTs,
  card numbers at ingest (catches misconfigured `scrubPii` flags too).
- **Token grace period** — opt-in 24h overlap on regenerate for zero-downtime
  rotation.

## 0.1.1

- README: "Get your token" section pointing to the Pionne mobile app.

## 0.1.0

- Initial release.
- Auto-capture via `set_exception_handler` + `register_shutdown_function`.
- Laravel auto-discover service provider.
- Symfony `kernel.exception` event listener (with `#[AsEventListener]`
  attribute for Symfony 6.3+ autoconfigure).
- Runtime context (PHP version, OS, hostname, pid, cwd).
- Single dependency: `ext-curl` (built into PHP).
