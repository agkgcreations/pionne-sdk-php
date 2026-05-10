<?php

declare(strict_types=1);

namespace Pionne;

use Throwable;

/**
 * Pionne SDK — error monitoring for PHP.
 *
 * Stateless namespace. Call {@see Pionne::init()} once during bootstrap, then
 * {@see Pionne::captureException()} / {@see Pionne::captureMessage()} from
 * anywhere. Auto-capture is wired up via {@see set_exception_handler()} and
 * {@see register_shutdown_function()} for fatals.
 *
 * Wire-format identical to @pionne/web, @pionne/node, @pionne/react-native.
 */
final class Pionne
{
    public const VERSION = '0.1.0';

    private const SDK_NAME = 'pionne.php';
    private const DEFAULT_ENDPOINT = 'https://pionne.agkgcreations.fr/api/ingest';
    private const DEFAULT_GEO_ENDPOINT = 'https://ipapi.co/json/';
    private const DEFAULT_MAX_STACK = 50;

    /** @var array<string, mixed>|null */
    private static ?array $config = null;

    /** @var array<string, mixed> */
    private static array $staticContext = [];

    /** @var ?callable(Throwable): void */
    private static $previousExceptionHandler = null;

    /**
     * @param array{
     *     token: string,
     *     endpoint?: string,
     *     release?: string,
     *     environment?: string,
     *     enabled?: bool,
     *     captureUncaughtExceptions?: bool,
     *     captureFatals?: bool,
     *     autoContext?: bool,
     *     userIdAnon?: string,
     *     tags?: array<string, string>,
     *     maxStackFrames?: int,
     *     beforeSend?: callable,
     *     sendGeography?: bool,
     *     geographyEndpoint?: string
     * } $options
     */
    public static function init(array $options): void
    {
        try {
            if (! isset($options['token']) || ! self::validateToken($options['token'])) {
                self::devWarn('Missing or invalid token (expected pio_live_<≥16 chars>, no placeholders).');
                return;
            }
            $endpoint = $options['endpoint'] ?? self::DEFAULT_ENDPOINT;
            $isDev = (getenv('APP_ENV') ?: 'production') !== 'production';
            if (! self::validateEndpoint($endpoint, $isDev)) {
                self::devWarn("Refusing non-HTTPS endpoint in production: $endpoint");
                return;
            }

        $autoContext = $options['autoContext'] ?? true;
        self::$staticContext = $autoContext ? self::gatherStaticContext() : [];

        self::$config = [
            'token' => $options['token'],
            'endpoint' => $options['endpoint'] ?? self::DEFAULT_ENDPOINT,
            'release' => $options['release'] ?? null,
            'environment' => $options['environment'] ?? (getenv('APP_ENV') ?: 'production'),
            'enabled' => $options['enabled'] ?? true,
            'captureUncaughtExceptions' => $options['captureUncaughtExceptions'] ?? true,
            'captureFatals' => $options['captureFatals'] ?? true,
            'userIdAnon' => $options['userIdAnon'] ?? null,
            'tags' => $options['tags'] ?? null,
            'maxStackFrames' => $options['maxStackFrames'] ?? self::DEFAULT_MAX_STACK,
            'beforeSend' => $options['beforeSend'] ?? null,
            'sendGeography' => $options['sendGeography'] ?? false,
            'geographyEndpoint' => $options['geographyEndpoint'] ?? self::DEFAULT_GEO_ENDPOINT,
        ];

        if (self::$config['captureUncaughtExceptions']) {
            self::$previousExceptionHandler = set_exception_handler([self::class, 'handleUncaught']);
        }
        if (self::$config['captureFatals']) {
            register_shutdown_function([self::class, 'handleShutdown']);
        }
        if (self::$config['sendGeography']) {
            self::fetchGeography(self::$config['geographyEndpoint']);
        }
        } catch (Throwable $e) {
            // Defensive — a monitoring SDK that crashes the host process
            // is worse than no monitoring. Swallow + warn in dev only.
            self::devWarn('init failed silently — monitoring disabled. ' . $e->getMessage());
            self::$config = null;
        }
    }

    /**
     * Stricter token format guard — minimum entropy + reject obvious
     * placeholders devs sometimes commit. Mirrors @pionne/* SDKs.
     */
    private static function validateToken(mixed $token): bool
    {
        if (! is_string($token)) return false;
        if (! str_starts_with($token, 'pio_live_')) return false;
        if (strlen($token) < strlen('pio_live_') + 16) return false;
        $lower = strtolower($token);
        foreach (['xxx', 'yyy', 'todo', 'fixme', 'replace', 'changeme'] as $bad) {
            if (str_contains($lower, $bad)) return false;
        }
        return true;
    }

    /**
     * Refuse non-HTTPS endpoints in production. http:// only allowed for
     * localhost / *.local in dev.
     */
    private static function validateEndpoint(string $endpoint, bool $isDev): bool
    {
        $parts = parse_url($endpoint);
        if ($parts === false || ! isset($parts['scheme'])) return false;
        if ($parts['scheme'] === 'https') return true;
        if ($parts['scheme'] !== 'http') return false;
        if (! $isDev) return false;
        $host = strtolower($parts['host'] ?? '');
        return in_array($host, ['localhost', '127.0.0.1', '0.0.0.0', '[::1]'], true)
            || str_ends_with($host, '.local');
    }

    private static function devWarn(string $msg): void
    {
        if (PHP_SAPI === 'cli' || (defined('PIONNE_DEBUG') && PIONNE_DEBUG)) {
            fwrite(STDERR, "[Pionne] $msg\n");
        }
    }

    public static function captureException(Throwable $e, array $extra = []): void
    {
        $event = self::buildEvent($e, $extra['level'] ?? 'error', 'manual', true, $extra);
        if ($event !== null) {
            self::send($event);
        }
    }

    public static function captureMessage(string $message, array $extra = []): void
    {
        $fakeError = new \RuntimeException($message);
        $event = self::buildEvent(
            $fakeError,
            $extra['level'] ?? 'info',
            'manual',
            true,
            array_merge(['exception_type' => 'Message'], $extra),
        );
        if ($event !== null) {
            self::send($event);
        }
    }

    public static function setUser(?string $userIdAnon): void
    {
        if (self::$config === null) {
            return;
        }
        self::$config['userIdAnon'] = $userIdAnon;
    }

    /**
     * @param array<string, string>|null $tags
     */
    public static function setTags(?array $tags): void
    {
        if (self::$config === null) {
            return;
        }
        self::$config['tags'] = $tags;
    }

    public static function setEnabled(bool $enabled): void
    {
        if (self::$config === null) {
            return;
        }
        self::$config['enabled'] = $enabled;
    }

    /**
     * Internal — installed via set_exception_handler. Public so callers can
     * also delegate from frameworks (e.g. Laravel reportable handler).
     */
    public static function handleUncaught(Throwable $e): void
    {
        $event = self::buildEvent($e, 'fatal', 'set_exception_handler', false);
        if ($event !== null) {
            self::send($event);
        }

        // Re-dispatch to whatever was previously installed (so nothing else
        // gets silently swallowed, e.g. framework error pages).
        if (self::$previousExceptionHandler !== null) {
            (self::$previousExceptionHandler)($e);
        } else {
            throw $e;
        }
    }

    /**
     * Internal — installed via register_shutdown_function. Catches fatals
     * (E_ERROR, E_PARSE, E_COMPILE_ERROR) that bypass set_exception_handler.
     */
    public static function handleShutdown(): void
    {
        $err = error_get_last();
        if ($err === null) {
            return;
        }
        $fatalMask = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR;
        if (($err['type'] & $fatalMask) === 0) {
            return;
        }
        $synthetic = new \ErrorException(
            $err['message'],
            0,
            $err['type'],
            $err['file'],
            $err['line'],
        );
        $event = self::buildEvent($synthetic, 'fatal', 'shutdown_handler', false);
        if ($event !== null) {
            self::send($event);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function buildEvent(
        Throwable $e,
        string $level,
        string $mechanism,
        bool $handled,
        array $extra = [],
    ): ?array {
        if (self::$config === null || ! self::$config['enabled']) {
            return null;
        }

        $event = array_merge(
            self::$staticContext,
            [
                'exception_type' => $extra['exception_type'] ?? (new \ReflectionClass($e))->getShortName(),
                'message' => $e->getMessage(),
                'stack' => self::parseStack($e, self::$config['maxStackFrames']),
                'level' => $level,
                'environment' => self::$config['environment'],
                'mechanism' => ['type' => $mechanism, 'handled' => $handled],
            ],
            self::$config['release'] !== null ? ['release' => self::$config['release']] : [],
            self::$config['userIdAnon'] !== null ? ['user_id_anon' => self::$config['userIdAnon']] : [],
            self::$config['tags'] !== null ? ['tags' => self::$config['tags']] : [],
        );

        // Caller-supplied extras override defaults.
        foreach ($extra as $k => $v) {
            if ($k === 'level') {
                continue;
            }
            $event[$k] = $v;
        }

        if (is_callable(self::$config['beforeSend'])) {
            $out = (self::$config['beforeSend'])($event);
            if (! is_array($out)) {
                return null;
            }
            return $out;
        }
        return $event;
    }

    /**
     * @return array<int, string>
     */
    private static function parseStack(Throwable $e, int $max): array
    {
        $frames = [];
        $frames[] = sprintf('%s(%d)', $e->getFile(), $e->getLine());
        foreach ($e->getTrace() as $f) {
            if (count($frames) >= $max) {
                break;
            }
            $file = $f['file'] ?? '<unknown>';
            $line = $f['line'] ?? 0;
            $func = ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? '');
            $frames[] = sprintf('%s @ %s:%d', $func, $file, $line);
        }
        return $frames;
    }

    /**
     * @return array<string, mixed>
     */
    private static function gatherStaticContext(): array
    {
        $tz = date_default_timezone_get();
        $contexts = [
            'sdk' => ['name' => self::SDK_NAME, 'version' => self::VERSION],
            'runtime' => [
                'name' => 'php',
                'version' => PHP_VERSION,
                'sapi' => PHP_SAPI,
                'zts' => PHP_ZTS,
            ],
            'os' => [
                'name' => PHP_OS_FAMILY,
                'version' => php_uname('r'),
                'arch' => php_uname('m'),
            ],
            'app' => [
                'hostname' => gethostname() ?: null,
                'pid' => getmypid() ?: null,
                'cwd' => getcwd() ?: null,
            ],
        ];

        return [
            'os_name' => PHP_OS_FAMILY,
            'os_version' => php_uname('r'),
            'timezone' => $tz,
            'contexts' => $contexts,
        ];
    }

    /**
     * Best-effort IP→geo lookup performed at init time. Mutates
     * {@see self::$staticContext} so subsequent events carry the location
     * under `contexts.geo`. Failures are silent — a monitoring SDK must
     * never crash or stall the host process.
     *
     * Uses a short curl timeout (4 s connect+read) so we don't slow down
     * application bootstrap if the geo provider is unreachable.
     */
    private static function fetchGeography(string $endpoint): void
    {
        try {
            $ch = curl_init($endpoint);
            if ($ch === false) {
                return;
            }
            curl_setopt_array($ch, [
                CURLOPT_HTTPGET => true,
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 4,
                CURLOPT_CONNECTTIMEOUT => 2,
            ]);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if (! is_string($body) || $code < 200 || $code >= 300) {
                return;
            }
            $decoded = json_decode($body, true);
            if (! is_array($decoded)) {
                return;
            }

            $geo = [];
            if (isset($decoded['city']) && is_string($decoded['city']) && $decoded['city'] !== '') {
                $geo['city'] = $decoded['city'];
            }
            if (isset($decoded['region']) && is_string($decoded['region']) && $decoded['region'] !== '') {
                $geo['region'] = $decoded['region'];
            }
            if (isset($decoded['country_name']) && is_string($decoded['country_name']) && $decoded['country_name'] !== '') {
                $geo['country'] = $decoded['country_name'];
            } elseif (isset($decoded['country']) && is_string($decoded['country']) && $decoded['country'] !== '') {
                $geo['country'] = $decoded['country'];
            }
            if (isset($decoded['country_code']) && is_string($decoded['country_code']) && $decoded['country_code'] !== '') {
                $geo['country_code'] = $decoded['country_code'];
            }
            if (empty($geo)) {
                return;
            }

            $contexts = self::$staticContext['contexts'] ?? [];
            $contexts['geo'] = $geo;
            self::$staticContext['contexts'] = $contexts;
        } catch (Throwable) {
            // Best-effort: silently ignore lookup failures.
        }
    }

    /**
     * @param array<string, mixed> $event
     */
    private static function send(array $event): void
    {
        if (self::$config === null) {
            return;
        }
        try {
            $body = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($body === false) {
                return;
            }
            $ch = curl_init(self::$config['endpoint']);
            if ($ch === false) {
                return;
            }
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'X-Pionne-Token: ' . self::$config['token'],
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 2,
            ]);
            $resBody = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            // 401/403/422 = permanent config issues that retries won't fix.
            // Surface once per process (even in prod) via error_log so the
            // dev sees a misconfigured token / bundle / validation in the
            // PHP error log without having to attach a debugger or
            // sniff network. Keep silent on 5xx and on transport errors
            // (curl_exec === false) — those are transient.
            if (! self::$warnedPermFailure && in_array($code, [401, 403, 422], true)) {
                self::$warnedPermFailure = true;
                $rawBody = is_string($resBody) ? $resBody : '';
                $parsed = json_decode($rawBody, true);
                $serverMsg = is_array($parsed) && isset($parsed['message']) && is_string($parsed['message'])
                    ? $parsed['message']
                    : substr($rawBody, 0, 200);
                $expectedFormat = is_array($parsed) && isset($parsed['expected_format']) && is_string($parsed['expected_format'])
                    ? $parsed['expected_format']
                    : 'unknown';
                $sentAppId = isset($event['app_id']) && is_string($event['app_id'])
                    ? $event['app_id']
                    : '<unset>';

                if ($code === 403 && preg_match('/bundle\s*id/i', $serverMsg)) {
                    error_log(
                        "[Pionne] Bundle ID mismatch — your project rejects events from app_id=\"$sentAppId\". " .
                        "Server expected \"$expectedFormat\" (1st char masked for safety). " .
                        "Fix it in your Pionne project Settings → Bundle ID, or clear the field to disable the check. " .
                        'Subsequent rejections will be silent for this session.'
                    );
                } elseif ($code === 401) {
                    error_log(
                        "[Pionne] Token rejected (401). Check that the project token (starts with \"pio_live_…\") matches. " .
                        "Server said: $serverMsg. Subsequent rejections will be silent for this session."
                    );
                } elseif ($code === 422) {
                    error_log(
                        "[Pionne] Event rejected (422 validation): $serverMsg. " .
                        "Sent app_id=\"$sentAppId\". Subsequent rejections will be silent for this session."
                    );
                } else {
                    error_log(
                        "[Pionne] Event rejected (status=$code): $serverMsg. " .
                        "Sent app_id=\"$sentAppId\". Subsequent rejections will be silent for this session."
                    );
                }
            }
        } catch (Throwable) {
            // Best-effort: a monitoring SDK must never crash the host app.
        }
    }

    /**
     * Module-level flag — gates the permanent-failure warning to one
     * log per PHP process. PHP-FPM workers reset this on each pool
     * recycle, which is fine: we want the dev to see the warning at
     * least once per process lifetime, not on every crash.
     */
    private static bool $warnedPermFailure = false;
}
