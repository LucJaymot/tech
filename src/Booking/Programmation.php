<?php

namespace App\Booking;

use App\Entity\Booking;
use App\Entity\Passage;
use App\Planning\Horaires;

/**
 * Places a booking in its soirée's timetable. The same hours as an existing
 * passage make a B2B; a passage left without artists is removed.
 */
final class Programmation
{
    /**
     * @param string|null $debut "HH:MM", null with $fin to leave the time to be confirmed
     *
     * @throws \InvalidArgumentException when an hour is not "HH:MM"
     */
    public function placer(Booking $booking, ?string $debut, ?string $fin): void
    {
        $soiree = $booking->getSoiree();
        $actuel = $booking->getPassage();
        if (null === $debut || null === $fin || '' === trim($debut) || '' === trim($fin)) {
            $booking->setPassage(null);
            $this->retirerSiVide($actuel);

            return;
        }

        [$debutAt, $finAt] = Horaires::plage($soiree, $debut, $fin);
        if (null !== $actuel && $actuel->getDebut() == $debutAt && $actuel->getFin() == $finAt) {
            return;
        }
        foreach ($soiree->getPassages() as $passage) {
            if ($passage->getDebut() == $debutAt && $passage->getFin() == $finAt) {
                $booking->setPassage($passage);
                $this->retirerSiVide($actuel);

                return;
            }
        }

        // Alone on its passage: move it rather than leave an empty one behind.
        if (null !== $actuel && 1 === $actuel->getBookings()->count()) {
            $actuel->deplacer($debutAt, $finAt);

            return;
        }

        $booking->setPassage(new Passage($soiree, $debutAt, $finAt));
        $this->retirerSiVide($actuel);
    }

    /**
     * Takes the booking off its soirée, and its passage when it was alone on it.
     */
    public function retirer(Booking $booking): void
    {
        $passage = $booking->getPassage();
        $booking->setPassage(null);
        $this->retirerSiVide($passage);
        $booking->getSoiree()->getBookings()->removeElement($booking);
    }

    private function retirerSiVide(?Passage $passage): void
    {
        if (null !== $passage && $passage->getBookings()->isEmpty()) {
            // Orphan removal deletes it at the next flush.
            $passage->getSoiree()->getPassages()->removeElement($passage);
        }
    }
}
