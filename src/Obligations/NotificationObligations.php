<?php

namespace App\Obligations;

use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Notifier\Message\EmailMessage;
use Symfony\Component\Notifier\Notification\EmailNotificationInterface;
use Symfony\Component\Notifier\Notification\Notification;
use Symfony\Component\Notifier\Recipient\EmailRecipientInterface;

/**
 * A rétroplanning notification, e-mailed from templates/emails/{$template}.*.twig.
 * Another channel (push…) only needs its own as*Message() method here.
 */
final class NotificationObligations extends Notification implements EmailNotificationInterface
{
    /**
     * @param array<string, mixed> $contexte
     */
    public function __construct(
        string $sujet,
        private readonly string $template,
        private readonly array $contexte,
    ) {
        parent::__construct($sujet, ['email']);
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
}
