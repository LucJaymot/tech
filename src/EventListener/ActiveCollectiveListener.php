<?php

namespace App\EventListener;

use App\Collective\ActiveCollective;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Scopes Doctrine to the active collective on every main request.
 *
 * The filter is first reset before the firewall (priority 8), so that loading
 * the user to authenticate them never depends on a previous request's state
 * (long-running workers, test clients), then enabled once the user is known.
 */
final class ActiveCollectiveListener
{
    public function __construct(
        private readonly ActiveCollective $activeCollective,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 2048)]
    public function reset(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->activeCollective->reset();
        }
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 7)]
    public function initialize(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->activeCollective->initialize();
        }
    }
}
