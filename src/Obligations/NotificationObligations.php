<?php

namespace App\Obligations;

use App\Notifier\WebPushOptions;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Notifier\Message\EmailMessage;
use Symfony\Component\Notifier\Message\PushMessage;
use Symfony\Component\Notifier\Notification\EmailNotificationInterface;
use Symfony\Component\Notifier\Notification\Notification;
use Symfony\Component\Notifier\Notification\PushNotificationInterface;
use Symfony\Component\Notifier\Recipient\EmailRecipientInterface;
use Symfony\Component\Notifier\Recipient\RecipientInterface;

/**
 * A rétroplanning notification: e-mailed from templates/emails/{$template}.*.twig
 * and pushed to the recipient's devices (App\Notifier\WebPushTransport).
 */
final class NotificationObligations extends Notification implements EmailNotificationInterface, PushNotificationInterface
{
    /**
     * @param array<string, mixed> $contexte
     * @param string               $url      absolute, opened by a click on the push notification
     */
    public function __construct(
        string $sujet,
        private readonly string $template,
        private readonly array $contexte,
        private readonly string $url,
        string $texte = '',
    ) {
        parent::__construct($sujet, ['email', 'push']);
        $this->content($texte);
    }

    public function asEmailMessage(EmailRecipientInterface $recipient, ?string $transport = null): EmailMessage
    {
        return new EmailMessage((new TemplatedEmail())
            ->to($recipient->getEmail())
            ->subject($this->getSubject())
            ->htmlTemplate('emails/'.$this->template.'.html.twig')
            ->textTemplate('emails/'.$this->template.'.txt.twig')
            ->context($this->contexte));
    }

    public function asPushMessage(RecipientInterface $recipient, ?string $transport = null): ?PushMessage
    {
        if (!$recipient instanceof EmailRecipientInterface) {
            return null;
        }

        return new PushMessage($this->getSubject(), $this->getContent(), new WebPushOptions($recipient->getEmail(), $this->url));
    }
}
