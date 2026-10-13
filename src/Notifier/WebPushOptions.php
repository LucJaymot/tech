<?php

namespace App\Notifier;

use Symfony\Component\Notifier\Message\MessageOptionsInterface;

/**
 * Who a push message is for (the e-mail of the account, whose devices get
 * it) and the page its click opens.
 */
final readonly class WebPushOptions implements MessageOptionsInterface
{
    public function __construct(
        private string $email,
        public string $url,
    ) {
    }

    /**
     * @return array{email: string, url: string}
     */
    public function toArray(): array
    {
        return ['email' => $this->email, 'url' => $this->url];
    }

    public function getRecipientId(): string
    {
        return $this->email;
    }
}
