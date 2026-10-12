<?php

namespace App\EventListener;

use App\Entity\Artiste;
use App\Entity\Booking;
use App\Entity\LigneBudget;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Keeps one fee line in the budget per confirmed booking: created when the
 * booking is confirmed, updated when its fee or artist (or the artist's name)
 * changes, given its actual amount once paid. Whatever code saves the booking, the line follows
 * within the same flush.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class CachetsBudgetListener
{
    private const array CHAMPS_SUIVIS = ['statut', 'cachet', 'artiste'];

    public function onFlush(OnFlushEventArgs $args): void
    {
        $entityManager = $args->getObjectManager();
        $unitOfWork = $entityManager->getUnitOfWork();
        $metadata = $entityManager->getClassMetadata(LigneBudget::class);

        $bookings = $unitOfWork->getScheduledEntityInsertions();
        foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
            if ($entity instanceof Booking && [] !== array_intersect(self::CHAMPS_SUIVIS, array_keys($unitOfWork->getEntityChangeSet($entity)))) {
                $bookings[] = $entity;
            } elseif ($entity instanceof Artiste && \array_key_exists('nom', $unitOfWork->getEntityChangeSet($entity))) {
                array_push($bookings, ...$entityManager->getRepository(Booking::class)->findBy(['artiste' => $entity]));
            }
        }

        foreach ($bookings as $booking) {
            if (!$booking instanceof Booking) {
                continue;
            }

            $ligne = $booking->getLigneBudget();
            if ($booking->estConfirme() && null === $ligne) {
                $ligne = LigneBudget::pourCachet($booking);
                $entityManager->persist($ligne);
                $unitOfWork->computeChangeSet($metadata, $ligne);
            } elseif ($booking->estConfirme() && null !== $ligne) {
                if ($ligne->synchroniserCachet()) {
                    $unitOfWork->recomputeSingleEntityChangeSet($metadata, $ligne);
                }
            } elseif (null !== $ligne) {
                $booking->setLigneBudget(null);
                $ligne->getSoiree()->getLignesBudget()->removeElement($ligne);
                $entityManager->remove($ligne);
            }
        }
    }
}
