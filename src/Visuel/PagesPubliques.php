<?php

namespace App\Visuel;

use App\Collective\ActiveCollective;
use App\Entity\Booking;
use App\Entity\Passage;
use App\Entity\Soiree;
use App\Repository\PassageRepository;
use App\Repository\SoireeRepository;

/**
 * Public pages of soirées, found by slug outside the collective filter:
 * visitors have no account, hence no active collective.
 */
final class PagesPubliques
{
    public function __construct(
        private readonly SoireeRepository $soirees,
        private readonly PassageRepository $passages,
        private readonly VisuelsLineUp $visuels,
        private readonly ActiveCollective $activeCollective,
        private readonly IdentiteVisuelle $identite,
    ) {
    }

    public function soiree(string $slug): ?Soiree
    {
        return $this->activeCollective->withoutFilter(fn (): ?Soiree => $this->soirees->findOneBy(['slug' => $slug]));
    }

    public function vue(string $slug): ?VuePublique
    {
        return $this->activeCollective->withoutFilter(function () use ($slug): ?VuePublique {
            $soiree = $this->soirees->findOneBy(['slug' => $slug]);
            if (null === $soiree) {
                return null;
            }
            $lieu = $soiree->getLieu();

            return new VuePublique(
                slug: $soiree->getSlug(),
                soiree: $soiree->getNom(),
                collectif: $soiree->getCollective()->getName(),
                accent: $soiree->getCollective()->getCouleurAccent(),
                styleTitres: $soiree->getCollective()->getStyleTitres(),
                logo: $this->identite->urlLogo($soiree->getCollective()),
                date: $soiree->getDate(),
                ouverture: $soiree->getOuverture(),
                fermeture: $soiree->getFermeture(),
                lieu: $lieu?->getNom(),
                adresse: $lieu?->getAdresse(),
                timetable: array_map(static fn (Passage $p): array => [
                    'debut' => $p->getDebut(),
                    'fin' => $p->getFin(),
                    'artistes' => array_values($p->getBookings()->map(static fn (Booking $b): string => $b->getArtiste()->getNom())->toArray()),
                ], $this->passages->findTimetable($soiree)),
                visuelGenereLe: $this->visuels->genereLe($soiree, FormatVisuel::Carre),
            );
        });
    }
}
