<?php

namespace App\Visuel;

use App\Collective\ActiveCollective;
use App\Entity\Booking;
use App\Entity\Passage;
use App\Entity\Soiree;
use App\Repository\PassageRepository;
use App\Repository\SoireeRepository;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Twig\Environment;

/**
 * Line-up visuals of a soirée: SVG rendered from templates/visuels/lineup.svg.twig
 * and stored in the "visuels" storage, one file per FormatVisuel.
 */
final class VisuelsLineUp
{
    public function __construct(
        #[Target('visuels.storage')]
        private readonly FilesystemOperator $storage,
        private readonly Environment $twig,
        private readonly PassageRepository $passages,
        private readonly SoireeRepository $soirees,
        private readonly IdentiteVisuelle $identite,
        private readonly ActiveCollective $activeCollective,
        #[Autowire(param: 'app.timezone')]
        private readonly string $timezone,
    ) {
    }

    public function rendre(Soiree $soiree, FormatVisuel $format): string
    {
        $collective = $soiree->getCollective();
        $jour = new \IntlDateFormatter('fr_FR', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $this->timezone, null, 'EEEE d MMMM y');

        return $this->twig->render('visuels/lineup.svg.twig', [
            'largeur' => $format->largeur(),
            'hauteur' => $format->hauteur(),
            'soiree' => $soiree->getNom(),
            'jour' => (string) $jour->format($soiree->getDate()),
            'ouverture' => $soiree->getOuverture()->format('H\hi'),
            'lieu' => $soiree->getLieu()?->getNom(),
            'artistes' => array_values(array_filter(array_map(
                static fn (Passage $p): string => implode(' b2b ', $p->getBookings()->map(static fn (Booking $b): string => $b->getArtiste()->getNom())->toArray()),
                $this->passages->findTimetable($soiree),
            ))),
            'collectif' => $collective->getName(),
            'accent' => $collective->getCouleurAccent(),
            'logo' => $this->identite->logoEnDataUri($collective),
        ]);
    }

    /**
     * Outside the collective filter: it runs in the worker, without an active collective.
     */
    public function generer(Soiree $soiree): void
    {
        $this->activeCollective->withoutFilter(function () use ($soiree): void {
            foreach (FormatVisuel::cases() as $format) {
                $this->storage->write($this->chemin($soiree, $format), $this->rendre($soiree, $format), ['mimetype' => 'image/svg+xml']);
            }
        });
    }

    #[AsMessageHandler]
    public function __invoke(GenererVisuels $message): void
    {
        $soiree = $this->activeCollective->withoutFilter(fn (): ?Soiree => $this->soirees->find($message->soireeId));
        if (null !== $soiree) {
            $this->generer($soiree);
        }
    }

    /**
     * @return resource|null null until generated
     */
    public function lire(Soiree $soiree, FormatVisuel $format)
    {
        $chemin = $this->chemin($soiree, $format);

        return $this->storage->fileExists($chemin) ? $this->storage->readStream($chemin) : null;
    }

    /**
     * Unix time of the last generation, for cache busting; null until generated.
     */
    public function genereLe(Soiree $soiree, FormatVisuel $format): ?int
    {
        $chemin = $this->chemin($soiree, $format);

        return $this->storage->fileExists($chemin) ? $this->storage->lastModified($chemin) : null;
    }

    private function chemin(Soiree $soiree, FormatVisuel $format): string
    {
        return \sprintf('lineups/%s/%s.svg', $soiree->getSlug(), $format->value);
    }
}
