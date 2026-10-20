<?php

namespace App\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Security headers on every response; a header set by the controller is kept.
 */
#[AsEventListener]
final class EnTetesSecuriteListener
{
    public function __construct(
        #[Autowire('%kernel.environment%')]
        private readonly string $environnement,
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $headers = $event->getResponse()->headers;
        // Not in dev: HSTS on https://localhost would force HTTPS on every other local project for a year.
        if ($event->getRequest()->isSecure() && 'dev' !== $this->environnement && !$headers->has('Strict-Transport-Security')) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000');
        }
        foreach ([
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
        ] as $nom => $valeur) {
            if (!$headers->has($nom)) {
                $headers->set($nom, $valeur);
            }
        }
    }
}
