<?php

namespace App\Booking;

use App\Collective\ActiveCollective;
use App\Entity\Booking;
use App\Entity\Passage;
use App\Enum\BookingStatut;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Artist sheets shared without an account: signed, time-limited, read-only links.
 */
final class FeuillesArtistes
{
    public const string DUREE_PAR_DEFAUT = '+30 days';

    public function __construct(
        private readonly UriSigner $uriSigner,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly EntityManagerInterface $entityManager,
        private readonly ActiveCollective $activeCollective,
    ) {
    }

    /**
     * The signed link to share with the artist; it stops working at $expireLe.
     */
    public function lien(Booking $booking, ?\DateTimeImmutable $expireLe = null): string
    {
        $url = $this->urlGenerator->generate('app_feuille_artiste', ['booking' => $booking->getId()], UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->uriSigner->sign($url, $expireLe ?? new \DateTimeImmutable(self::DUREE_PAR_DEFAUT));
    }

    /**
     * Builds the sheet in one go, outside the collective filter: whoever holds
     * a valid link has no account, hence no active collective.
     */
    public function feuille(int $bookingId): ?FeuilleArtiste
    {
        return $this->activeCollective->withoutFilter(function () use ($bookingId): ?FeuilleArtiste {
            $booking = $this->entityManager->createQueryBuilder()
                ->select('b', 'a', 's', 'l', 'p')
                ->from(Booking::class, 'b')
                ->join('b.artiste', 'a')
                ->join('b.soiree', 's')
                ->leftJoin('s.lieu', 'l')
                ->leftJoin('b.passage', 'p')
                ->where('b.id = :id')
                ->setParameter('id', $bookingId)
                ->getQuery()
                ->getOneOrNullResult();
            if (!$booking instanceof Booking || BookingStatut::Annule === $booking->getStatutEnum()) {
                return null;
            }

            /** @var list<Passage> $passages */
            $passages = $this->entityManager->createQueryBuilder()
                ->select('p', 'pb', 'pa')
                ->from(Passage::class, 'p')
                ->leftJoin('p.bookings', 'pb')
                ->leftJoin('pb.artiste', 'pa')
                ->where('p.soiree = :soiree')
                ->setParameter('soiree', $booking->getSoiree())
                ->orderBy('p.debut')
                ->getQuery()
                ->getResult();

            $artistes = static fn (Passage $passage): array => array_values(array_map(
                static fn (Booking $b): string => $b->getArtiste()->getNom(),
                $passage->getBookings()->toArray(),
            ));
            $soiree = $booking->getSoiree();
            $lieu = $soiree->getLieu();
            $passage = $booking->getPassage();

            return new FeuilleArtiste(
                artiste: $booking->getArtiste()->getNom(),
                soiree: $soiree->getNom(),
                date: $soiree->getDate(),
                ouverture: $soiree->getOuverture(),
                fermeture: $soiree->getFermeture(),
                lieu: $lieu?->getNom(),
                adresse: $lieu?->getAdresse(),
                acces: $lieu?->getAcces(),
                passageDebut: $passage?->getDebut(),
                passageFin: $passage?->getFin(),
                partenairesB2B: null === $passage ? [] : array_values(array_diff($artistes($passage), [$booking->getArtiste()->getNom()])),
                timetable: array_map(static fn (Passage $p): array => ['debut' => $p->getDebut(), 'fin' => $p->getFin(), 'artistes' => $artistes($p)], $passages),
                rider: $booking->getRider(),
                hospitalite: $booking->getHospitalite(),
            );
        });
    }
}
