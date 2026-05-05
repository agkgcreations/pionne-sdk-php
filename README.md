# pionne/pionne — PHP SDK

Error monitoring SDK for PHP — by [Pionne](https://pionne.agkgcreations.fr).

Auto-captures uncaught exceptions and fatals, ships rich runtime context (PHP version, OS, hostname, pid). Single dependency on `ext-curl` (built into PHP). Wire-format compatible with `@pionne/web`, `@pionne/node`, `@pionne/react-native`, `pionne_flutter`.

Includes drop-in integrations for **Laravel** (auto-discover) and **Symfony** (event listener).

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

## API

```php
Pionne::captureException(\Throwable $e, array $extra = []);
Pionne::captureMessage(string $message, array $extra = []);
Pionne::setUser(?string $userIdAnon);
Pionne::setTags(?array $tags);
Pionne::setEnabled(bool $enabled);
```

## License

MIT
