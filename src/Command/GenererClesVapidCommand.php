<?php

namespace App\Command;

use Minishlink\WebPush\VAPID;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:vapid:generer', description: 'Génère une paire de clés VAPID pour les notifications push')]
final class GenererClesVapidCommand
{
    public function __invoke(SymfonyStyle $io): int
    {
        $cles = VAPID::createVapidKeys();
        $io->text('À mettre dans .env.local, ou en prod avec bin/console secrets:set :');
        $io->writeln(\sprintf('WEBPUSH_DSN=webpush://%s:%s@default?subject=mailto:contact@example.com', $cles['publicKey'], $cles['privateKey']));
        $io->writeln(\sprintf('VAPID_PUBLIC_KEY=%s', $cles['publicKey']));

        return 0;
    }
}
