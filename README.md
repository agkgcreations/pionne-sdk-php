# pionne/pionne — PHP SDK

Error monitoring SDK for PHP — by [Pionne](https://pionne.agkgcreations.fr).

Auto-captures uncaught exceptions and fatals, ships rich runtime context (PHP version, OS, hostname, pid). Single dependency on `ext-curl` (built into PHP). Wire-format compatible with `@pionne/web`, `@pionne/node`, `@pionne/react-native`, `pionne_flutter`.

Includes drop-in integrations for **Laravel** (auto-discover) and **Symfony** (event listener).

## 🎫 Get your token

Pionne is **mobile-first**: you sign up, create projects, and watch your error feed **from the Pionne mobile app**, not a web dashboard.

1. **Download the app**:
   - 🍎 [App Store](https://apps.apple.com/app/pionne) *(coming soon)*
   - 🤖 [Google Play](https://play.google.com/store/apps/details?id=fr.agkgcreations.pionne) *(coming soon)*
2. Create your account (30 days free, no card required)
3. **+ New project** → pick **Laravel** or **Symfony** → copy the token displayed (`pio_live_…`)
4. Paste it into your `.env` as `PIONNE_TOKEN=…`

⚠️ The token is only shown **once** at project creation — store it in `.env` (gitignored), never commit it to source.

## Install

```bash
composer require pionne/pionne
```

## Plain PHP

```php
use Pionne\Pionne;

Pionne::init([
    'token' => 'pio_live_xxx',
    'release' => '1.0.0',
    'environment' => 'production',
]);

try {
    process();
} catch (\Throwable $e) {
    Pionne::captureException($e, ['tags' => ['feature' => 'checkout']]);
    throw $e;
}
```

## Laravel

Auto-discovered. Just set the env vars:

```env
PIONNE_TOKEN=pio_live_xxx
PIONNE_RELEASE=1.0.0
# PIONNE_AUTO_INSTALL=false # to opt out
```

Done. Every reportable exception is now forwarded to Pionne.

## Symfony

```yaml
# config/services.yaml
services:
    Pionne\Symfony\PionneExceptionListener:
        tags:
            - { name: kernel.event_listener, event: kernel.exception }
```

```php
// public/index.php (very top, before Kernel boot)
use Pionne\Pionne;

Pionne::init(['token' => $_ENV['PIONNE_TOKEN']]);
```

Symfony 6.3+ users can rely on the `#[AsEventListener]` attribute that ships with the listener — autoconfigure will pick it up automatically.

## Geography (opt-in)

Approximate server location (city, region, country) attached to every event,
just like Sentry. Off by default for privacy — flip `sendGeography` to enable:

```php
Pionne::init([
    'token' => 'pio_live_xxx',
    'sendGeography' => true,
]);
```

Resolved once at startup via a free IP→geo lookup (`https://ipapi.co/json/`
by default), with a 4 s timeout. If the lookup fails the SDK silently keeps
shipping events without geo. Override the endpoint via `geographyEndpoint`
if you have your own.

For Laravel, set `PIONNE_GEOGRAPHY=true` in your `.env`.

## API

```php
Pionne::captureException(\Throwable $e, array $extra = []);
Pionne::captureMessage(string $message, array $extra = []);
Pionne::setUser(?string $userIdAnon);
Pionne::setTags(?array $tags);
Pionne::setEnabled(bool $enabled);
```

## Rate limit serveur

L'API Pionne cap **600 req/min/token** (= 10/sec) sur tous les endpoints publics (`/ingest`, `/sessions`, `/feedback`). Au-delà → `HTTP 429` avec un header `Retry-After`. Le SDK fait silencieusement échouer (try/catch interne).

Empêche un token leaké, un worker PHP qui throw en boucle, ou un endpoint hammered par un bot, de drainer ton infra ou ton quota mensuel. Pour un site PHP qui sert des milliers de requêtes/sec, **utilise `sampleRate`** (`'sampleRate' => 0.1` envoie 1 event sur 10) pour rester sous le cap par token tout en gardant un signal statistique.

Voir [doc rate limits](https://pionne.agkgcreations.fr/security/rate-limits).

## License

MIT
