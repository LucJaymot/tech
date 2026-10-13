<?php

namespace App\Notifier;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * A browser's PushSubscription, as sent by assets/controllers/notifications_controller.js.
 */
final readonly class AbonnementPushPayload
{
    /**
     * @param array{p256dh?: string, auth?: string} $keys
     */
    public function __construct(
        // The server posts to this URL: only the known push services, never an arbitrary host.
        #[Assert\NotBlank]
        #[Assert\Length(max: 2000)]
        #[Assert\Regex('#^https://(fcm\.googleapis\.com|updates\.push\.services\.mozilla\.com|web\.push\.apple\.com|[a-z0-9-]+\.notify\.windows\.com)/#')]
        public string $endpoint,
        #[Assert\Collection(fields: [
            'p256dh' => [new Assert\NotBlank(), new Assert\Length(max: 255), new Assert\Regex('/^[A-Za-z0-9_-]+={0,2}$/')],
            'auth' => [new Assert\NotBlank(), new Assert\Length(max: 255), new Assert\Regex('/^[A-Za-z0-9_-]+={0,2}$/')],
        ])]
        public array $keys = [],
    ) {
    }
}
