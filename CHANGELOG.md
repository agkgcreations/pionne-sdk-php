# Changelog

## 0.3.3 — 2026-05-10

### Fixed

- **Actionable error message on permanent ingest rejection.** `send()`
  now reads the cURL response body + status code (previously thrown
  away), parses the JSON error envelope on 401/403/422, distinguishes
  the failure modes (Bundle ID mismatch / Token rejected / 422
  validation), and emits an `error_log()` line (once per process,
  even in prod) that includes the `app_id` actually sent and the
  masked `expected_format` returned by the server. Fixes the silent
  rejection footgun where a misconfigured token or stale bundle
  pinning would drop events without any visible signal in the PHP
  error log.

## 0.3.2 — 2026-05-08

### Documentation

- README : nouveau bloc "Rate limit serveur" qui documente le cap
  600 req/min/token côté API Pionne (= 10/sec). Recommandation
  pratique d'utiliser `'sampleRate' => 0.1` sur les sites PHP
  high-traffic pour rester sous le cap par token tout en gardant
  un signal statistique. Aucun changement de code SDK.

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
