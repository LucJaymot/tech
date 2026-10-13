<?php

namespace App\Soiree;

use App\Bilan\BilanSoiree;
use App\Budget\BudgetTotaux;
use App\Entity\Booking;
use App\Entity\GuestList;
use App\Entity\Passage;
use App\Entity\Soiree;
use App\Entity\TacheAdmin;
use App\Planning\Trou;
use App\Repository\CreneauRepository;
use App\Repository\GuestListRepository;
use App\Repository\PassageRepository;
use App\Repository\TacheAdminRepository;
use Psr\Clock\ClockInterface;

/**
 * What the page of a soirée shows of each module, computed from the
 * existing repositories and services.
 */
final class ResumeSoiree
{
    public function __construct(
        private readonly PassageRepository $passages,
        private readonly TacheAdminRepository $taches,
        private readonly CreneauRepository $creneaux,
        private readonly GuestListRepository $guestLists,
        private readonly BilanSoiree $bilan,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Days left until the soirée: "J-12", "Jour J", then "J+3".
     */
    public function compteARebours(Soiree $soiree): string
    {
        return self::compteAReboursLe($soiree->getDate(), $this->clock->now());
    }

    /**
     * Counted in calendar days in the soirée's time zone, so that neither a
     * UTC clock nor a daylight saving change shifts it by one.
     */
    public static function compteAReboursLe(\DateTimeImmutable $date, \DateTimeImmutable $maintenant): string
    {
        $jour = static fn (\DateTimeInterface $d): \DateTimeImmutable => new \DateTimeImmutable($d->format('Y-m-d'), new \DateTimeZone('UTC'));
        $jours = (int) $jour($maintenant->setTimezone($date->getTimezone()))->diff($jour($date))->format('%r%a');

        return match (true) {
            $jours > 0 => 'J-'.$jours,
            0 === $jours => 'Jour J',
            default => 'J+'.-$jours,
        };
    }

    /**
     * @return array{ouverture: \DateTimeImmutable, fermeture: \DateTimeImmutable}
     */
    public function horaires(Soiree $soiree): array
    {
        return ['ouverture' => $soiree->getOuverture(), 'fermeture' => $soiree->getFermeture()];
    }

    /**
     * @return list<array{debut: \DateTimeImmutable, fin: \DateTimeImmutable, artistes: list<string>}>
     */
    public function lineUp(Soiree $soiree): array
    {
        return array_map(static fn (Passage $p): array => [
            'debut' => $p->getDebut(),
            'fin' => $p->getFin(),
            'artistes' => array_values($p->getBookings()->map(static fn (Booking $b): string => $b->getArtiste()->getNom())->toArray()),
        ], $this->passages->findTimetable($soiree));
    }

    /**
     * @return array{ouvertes: int, en_retard: int, prochaine: ?TacheAdmin, statut: string}
     */
    public function taches(Soiree $soiree): array
    {
        $aujourdhui = $this->clock->now()->setTime(0, 0);
        $ouvertes = array_values(array_filter($this->taches->findPourSoiree($soiree), static fn (TacheAdmin $t): bool => !$t->estFaite()));
        $enRetard = \count(array_filter($ouvertes, static fn (TacheAdmin $t): bool => $t->getEcheance() < $aujourdhui));
        $prochaine = $ouvertes[0] ?? null;

        return [
            'ouvertes' => \count($ouvertes),
            'en_retard' => $enRetard,
            'prochaine' => $prochaine,
            'statut' => match (true) {
                $enRetard > 0 => 'bloquant',
                null !== $prochaine && $prochaine->getEcheance() <= $aujourdhui->modify('+7 days') => 'attention',
                default => 'ok',
            },
        ];
    }

    /**
     * @return array{creneaux: int, manquants: int}
     */
    public function benevoles(Soiree $soiree): array
    {
        return [
            'creneaux' => $this->creneaux->count(['soiree' => $soiree]),
            'manquants' => array_sum(array_map(static fn (Trou $t): int => $t->manquants(), $this->creneaux->findTrous($soiree))),
        ];
    }

    /**
     * @return array{listes: int, personnes: int, arrives: int}
     */
    public function guestLists(Soiree $soiree): array
    {
        $listes = $this->guestLists->findPourSoiree($soiree);
        $arrives = 0;
        foreach ($listes as $liste) {
            foreach ($liste->getInvites() as $invite) {
                $arrives += $invite->estPointe() ? 1 + $invite->getAccompagnants() : 0;
            }
        }

        return [
            'listes' => \count($listes),
            'personnes' => array_sum(array_map(static fn (GuestList $l): int => $l->personnes(), $listes)),
            'arrives' => $arrives,
        ];
    }

    public function budget(Soiree $soiree): BudgetTotaux
    {
        return $this->bilan->pour($soiree)['totaux'];
    }
}
