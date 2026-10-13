<?php

namespace App\Notifier;

use App\Repository\AbonnementPushRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\Notifier\Exception\UnsupportedSchemeException;
use Symfony\Component\Notifier\Transport\Dsn;
use Symfony\Component\Notifier\Transport\TransportFactoryInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * "webpush://PUBLIC_KEY:PRIVATE_KEY@default?subject=mailto:…", the VAPID
 * keys being base64url. Generate a pair with bin/console app:vapid:generer.
 */
final class WebPushTransportFactory implements TransportFactoryInterface
{
    public function __construct(
        private readonly AbonnementPushRepository $abonnements,
        private readonly EntityManagerInterface $entityManager,
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    public function create(Dsn $dsn): WebPushTransport
    {
        if (!$this->supports($dsn)) {
            throw new UnsupportedSchemeException($dsn, 'webpush', ['webpush']);
        }

        return new WebPushTransport($dsn->getUser() ?? '', $dsn->getPassword() ?? '', $dsn->getRequiredOption('subject'), $this->abonnements, $this->entityManager, new Psr18Client($this->httpClient));
    }

    public function supports(Dsn $dsn): bool
    {
        return 'webpush' === $dsn->getScheme();
    }
}
