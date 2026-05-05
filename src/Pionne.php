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
     *     beforeSend?: callable
     * } $options
     */
    public static function init(array $options): void
    {
        if (! isset($options['token']) || ! str_starts_with($options['token'], 'pio_live_')) {
            if (PHP_SAPI === 'cli' || (defined('PIONNE_DEBUG') && PIONNE_DEBUG)) {
                fwrite(STDERR, "[Pionne] Missing or invalid token (must start with pio_live_).\n");
            }
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
        ];

        if (self::$config['captureUncaughtExceptions']) {
            self::$previousExceptionHandler = set_exception_handler([self::class, 'handleUncaught']);
        }
        if (self::$config['captureFatals']) {
            register_shutdown_function([self::class, 'handleShutdown']);
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
            curl_exec($ch);
            curl_close($ch);
        } catch (Throwable) {
            // Best-effort: a monitoring SDK must never crash the host app.
        }
    }
}
