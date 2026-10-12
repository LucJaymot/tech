<?php

namespace App\EventListener;

use App\Entity\Booking;
use App\Entity\BookingTransition;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Workflow\Attribute\AsCompletedListener;
use Symfony\Component\Workflow\Event\CompletedEvent;

/**
 * Keeps the history of a booking: every applied transition becomes a
 * BookingTransition, saved with the booking's new status on the next flush.
 */
final class BookingTransitionListener
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
    ) {
    }

    /**
     * @param CompletedEvent<object> $event
     */
    #[AsCompletedListener(workflow: 'booking')]
    public function onCompleted(CompletedEvent $event): void
    {
        $booking = $event->getSubject();
        $transition = $event->getTransition();
        if (!$booking instanceof Booking || null === $transition) {
            return;
        }

        $user = $this->security->getUser();
        $this->entityManager->persist(new BookingTransition(
            $booking,
            $transition->getName(),
            $transition->getFroms()[0],
            $transition->getTos()[0],
            $user instanceof User ? $user : null,
        ));
    }
}
