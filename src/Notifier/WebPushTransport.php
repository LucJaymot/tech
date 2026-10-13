<?php

namespace App\Notifier;

use App\Repository\AbonnementPushRepository;
use Doctrine\ORM\EntityManagerInterface;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Http\Client\ClientInterface;
use Symfony\Component\Notifier\Exception\UnsupportedMessageTypeException;
use Symfony\Component\Notifier\Message\MessageInterface;
use Symfony\Component\Notifier\Message\PushMessage;
use Symfony\Component\Notifier\Message\SentMessage;
use Symfony\Component\Notifier\Transport\TransportInterface;

/**
 * Notifier transport for the browsers' Web Push: sends a PushMessage to
 * every device of its recipient (App\Entity\AbonnementPush), signed with the
 * VAPID keys, and forgets the devices the push service no longer knows.
 */
final class WebPushTransport implements TransportInterface
{
    public function __construct(
        private readonly string $clePublique,
        private readonly string $clePrivee,
        private readonly string $sujet,
        private readonly AbonnementPushRepository $abonnements,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClientInterface $client,
    ) {
    }

    public function send(MessageInterface $message): SentMessage
    {
        if (!$message instanceof PushMessage || !$message->getOptions() instanceof WebPushOptions) {
            throw new UnsupportedMessageTypeException(self::class, PushMessage::class.' with '.WebPushOptions::class, $message);
        }
        $options = $message->getOptions();

        $webPush = new WebPush(['VAPID' => ['subject' => $this->sujet, 'publicKey' => $this->clePublique, 'privateKey' => $this->clePrivee]], client: $this->client);
        $parEndpoint = [];
        $payload = json_encode(['title' => $message->getSubject(), 'body' => $message->getContent(), 'url' => $options->url], \JSON_THROW_ON_ERROR);
        foreach ($this->abonnements->findPourEmail($options->getRecipientId()) as $abonnement) {
            $parEndpoint[$abonnement->getEndpoint()] = $abonnement;
            $webPush->queueNotification(new Subscription($abonnement->getEndpoint(), $abonnement->getCleP256dh(), $abonnement->getCleAuth(), 'aes128gcm'), $payload);
        }

        foreach ($webPush->flush() as $rapport) {
            if ($rapport->isSubscriptionExpired() && isset($parEndpoint[$rapport->getEndpoint()])) {
                $this->entityManager->remove($parEndpoint[$rapport->getEndpoint()]);
            }
        }
        $this->entityManager->flush();

        return new SentMessage($message, (string) $this);
    }

    public function supports(MessageInterface $message): bool
    {
        return $message instanceof PushMessage;
    }

    public function __toString(): string
    {
        return 'webpush://default';
    }
}
