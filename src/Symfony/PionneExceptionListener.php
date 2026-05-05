<?php

declare(strict_types=1);

namespace Pionne\Symfony;

use Pionne\Pionne;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;

/**
 * Symfony exception listener. Register it via `services.yaml`:
 *
 *     services:
 *       Pionne\Symfony\PionneExceptionListener:
 *         tags:
 *           - { name: kernel.event_listener, event: kernel.exception, priority: 0 }
 *
 * Or rely on the PHP attribute (Symfony 6.3+) — autoconfigure picks it up.
 *
 * Pionne::init() must be called once at boot (e.g. in `public/index.php`
 * before `Kernel` is created, or in a compiler pass) — typically:
 *
 *     Pionne::init(['token' => $_ENV['PIONNE_TOKEN']]);
 */
#[AsEventListener(event: 'kernel.exception')]
final class PionneExceptionListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        Pionne::captureException($event->getThrowable());
    }
}
