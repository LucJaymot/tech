<?php

namespace App\GuestList;

use App\Entity\Invite;
use App\Entity\Soiree;
use App\Entity\User;
use App\Enum\ResultatPointage;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Symfony\Component\Uid\Uuid;

/**
 * Door check-in. Devices download the guests, check them in offline with
 * their own ids and times, and send them back; sending a batch again is
 * harmless, and the earliest check-in of a guest wins.
 */
final class Pointage
{
    public const int LOT_MAX = 500;

    /** Tolerated clock drift of a device, ahead of the server. */
    private const string AVANCE_MAX = '+5 minutes';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Every guest of the soirée, for the door devices.
     *
     * @return list<array{id: int, nom: string, accompagnants: int, liste: string, pointe_at: ?string, pointage_id: ?string}>
     */
    public function liste(Soiree $soiree): array
    {
        /** @var list<Invite> $invites */
        $invites = $this->entityManager->createQueryBuilder()
            ->select('i', 'g', 'b', 'a', 'm')
            ->from(Invite::class, 'i')
            ->join('i.guestList', 'g')
            ->leftJoin('g.booking', 'b')
            ->leftJoin('b.artiste', 'a')
            ->leftJoin('g.membre', 'm')
            ->where('g.soiree = :soiree')
            ->setParameter('soiree', $soiree)
            ->orderBy('i.nom')
            ->getQuery()
            ->getResult();

        return array_map(static fn (Invite $i): array => [
            'id' => (int) $i->getId(),
            'nom' => $i->getNom(),
            'accompagnants' => $i->getAccompagnants(),
            'liste' => $i->getGuestList()->getProprietaire(),
            'pointe_at' => $i->getPointeAt()?->format(\DATE_ATOM),
            'pointage_id' => $i->getPointageId(),
        ], $invites);
    }

    /**
     * @param list<mixed> $pointages as sent by a device: {id: uuid, invite: int, at: ISO 8601}
     *
     * @return list<array{id: string, invite: int, resultat: string, pointe_at: ?string, pointe_par: ?string}>
     */
    public function synchroniser(Soiree $soiree, array $pointages, ?User $par): array
    {
        $resultats = [];
        foreach (\array_slice($pointages, 0, self::LOT_MAX) as $pointage) {
            $resultats[] = $this->appliquer($soiree, \is_array($pointage) ? $pointage : [], $par);
        }

        return $resultats;
    }

    /**
     * @param array<mixed> $pointage
     *
     * @return array{id: string, invite: int, resultat: string, pointe_at: ?string, pointe_par: ?string}
     */
    private function appliquer(Soiree $soiree, array $pointage, ?User $par): array
    {
        $id = \is_string($pointage['id'] ?? null) ? strtolower($pointage['id']) : '';
        $inviteId = \is_int($pointage['invite'] ?? null) ? $pointage['invite'] : 0;
        $at = $this->instant($pointage['at'] ?? null);

        $invalide = ['id' => $id, 'invite' => $inviteId, 'resultat' => ResultatPointage::Invalide->value, 'pointe_at' => null, 'pointe_par' => null];
        if (!Uuid::isValid($id) || null === $at) {
            return $invalide;
        }

        return $this->entityManager->wrapInTransaction(function () use ($soiree, $id, $inviteId, $at, $par, $invalide): array {
            // Locked and re-read: another device may have checked the guest in meanwhile.
            $invite = $this->entityManager->createQueryBuilder()
                ->select('i')
                ->from(Invite::class, 'i')
                ->join('i.guestList', 'g')
                ->where('i.id = :id')
                ->andWhere('g.soiree = :soiree')
                ->setParameter('id', $inviteId)
                ->setParameter('soiree', $soiree)
                ->getQuery()
                ->setLockMode(LockMode::PESSIMISTIC_WRITE)
                ->setHint(Query::HINT_REFRESH, true)
                ->getOneOrNullResult();
            if (!$invite instanceof Invite) {
                return $invalide;
            }

            $ailleurs = $this->entityManager->getConnection()->fetchOne('SELECT 1 FROM invite WHERE pointage_id = ? AND id <> ?', [$id, $inviteId]);
            if (false !== $ailleurs) {
                return $invalide;
            }

            $resultat = $invite->pointer($id, $at, $par);
            $this->entityManager->flush();

            return [
                'id' => $id,
                'invite' => $inviteId,
                'resultat' => $resultat->value,
                'pointe_at' => $invite->getPointeAt()?->format(\DATE_ATOM),
                'pointe_par' => $invite->getPointePar()?->getDisplayName(),
            ];
        });
    }

    private function instant(mixed $valeur): ?\DateTimeImmutable
    {
        if (!\is_string($valeur) || 1 !== preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/', $valeur)) {
            return null;
        }

        try {
            $at = new \DateTimeImmutable($valeur)->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        } catch (\Exception) {
            return null;
        }

        // Stored to the second; a device far ahead of the server is not trusted.
        $at = $at->setTime((int) $at->format('H'), (int) $at->format('i'), (int) $at->format('s'));

        return $at > new \DateTimeImmutable(self::AVANCE_MAX) ? null : $at;
    }
}
