<?php

namespace App\Command;

use App\Rgpd\DonneesCollectif;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:collectif:exporter', description: 'Exporte en JSON toutes les données d\'un collectif (RGPD)')]
final class ExporterCollectifCommand
{
    public function __construct(
        private readonly DonneesCollectif $donnees,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        OutputInterface $output,
        #[Argument('Slug du collectif')] string $slug,
        #[Option('Fichier de sortie ; la sortie standard sinon')] ?string $fichier = null,
    ): int {
        $export = $this->donnees->exporter($slug);
        if (null === $export) {
            $io->error(\sprintf('Aucun collectif « %s ».', $slug));

            return Command::FAILURE;
        }

        $json = json_encode($export, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
        if (null === $fichier) {
            $output->writeln($json);
        } else {
            file_put_contents($fichier, $json) ?: throw new \RuntimeException(\sprintf('Impossible d\'écrire %s.', $fichier));
            $io->success(\sprintf('Export écrit dans %s.', $fichier));
        }

        return Command::SUCCESS;
    }
}
