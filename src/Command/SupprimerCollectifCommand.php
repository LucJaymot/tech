<?php

namespace App\Command;

use App\Rgpd\DonneesCollectif;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:collectif:supprimer', description: 'Supprime définitivement un collectif, ses données et ses fichiers (RGPD)')]
final class SupprimerCollectifCommand
{
    public function __construct(
        private readonly DonneesCollectif $donnees,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Slug du collectif')] string $slug,
        #[Option('Ne pas demander de confirmation')] bool $force = false,
    ): int {
        if (!$force && $slug !== $io->ask(\sprintf('Suppression définitive. Retapez « %s » pour confirmer', $slug))) {
            $io->warning('Rien n\'a été supprimé.');

            return Command::FAILURE;
        }

        if (!$this->donnees->supprimer($slug)) {
            $io->error(\sprintf('Aucun collectif « %s ».', $slug));

            return Command::FAILURE;
        }
        $io->success(\sprintf('Collectif « %s » supprimé, avec ses données et ses fichiers. Les comptes des membres sont conservés.', $slug));

        return Command::SUCCESS;
    }
}
