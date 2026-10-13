<?php

namespace App\GuestList;

use App\Entity\Booking;
use App\Entity\GuestList;
use App\Entity\Invite;
use App\Entity\Soiree;
use App\Entity\User;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Guest lists of soirées: created on first use, and kept within their quota
 * whoever adds guests (members in the app, artists from their signed link).
 */
final class GuestLists
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
        #[Autowire(param: 'app.guest_list.quota_membre')]
        private readonly int $quotaMembre,
    ) {
    }

    /**
     * The artist's list; its quota starts from the invitations of their hospitality.
     */
    public function pourBooking(Booking $booking): GuestList
    {
        $liste = $this->entityManager->getRepository(GuestList::class)->findOneBy(['booking' => $booking]);
        if (null === $liste) {
            $liste = GuestList::pourBooking($booking, $booking->getHospitalite()->invitations);
            $this->entityManager->persist($liste);
            $this->entityManager->flush();
        }

        return $liste;
    }

    public function pourMembre(Soiree $soiree, User $membre): GuestList
    {
        $liste = $this->entityManager->getRepository(GuestList::class)->findOneBy(['soiree' => $soiree, 'membre' => $membre]);
        if (null === $liste) {
            $liste = GuestList::pourMembre($soiree, $membre, $this->quotaMembre);
            $this->entityManager->persist($liste);
            $this->entityManager->flush();
        }

        return $liste;
    }

    /**
     * Adds a guest if the list has room for them and their companions. The
     * list row is locked meanwhile, so that concurrent additions cannot
     * exceed the quota together.
     *
     * @throws GuestListRefusee
     */
    public function ajouter(GuestList $liste, string $nom, int $accompagnants): Invite
    {
        return $this->entityManager->wrapInTransaction(function () use ($liste, $nom, $accompagnants): Invite {
            $this->entityManager->lock($liste, LockMode::PESSIMISTIC_WRITE);
            $quota = (int) $this->entityManager->getConnection()->fetchOne('SELECT quota FROM guest_list WHERE id = ?', [$liste->getId()]);
            $deja = (int) $this->entityManager->getConnection()->fetchOne('SELECT COALESCE(SUM(1 + accompagnants), 0) FROM invite WHERE guest_list_id = ?', [$liste->getId()]);

            $invite = new Invite($liste, $nom, $accompagnants);
            $violations = $this->validator->validate($invite);
            if (\count($violations) > 0) {
                $liste->getInvites()->removeElement($invite);

                throw new GuestListRefusee((string) $violations->get(0)->getMessage());
            }
            if ($deja + $invite->personnes() > $quota) {
                $liste->getInvites()->removeElement($invite);

                throw new GuestListRefusee(\sprintf('Plus assez de places : %d restante%s sur %d.', max(0, $quota - $deja), $quota - $deja > 1 ? 's' : '', $quota));
            }

            $this->entityManager->persist($invite);
            $this->entityManager->flush();

            return $invite;
        });
    }

    /**
     * @throws GuestListRefusee once the guest is checked in
     */
    public function retirer(Invite $invite): void
    {
        if ($invite->estPointe()) {
            throw new GuestListRefusee('Cet invité est déjà arrivé.');
        }

        $invite->getGuestList()->getInvites()->removeElement($invite);
        $this->entityManager->remove($invite);
        $this->entityManager->flush();
    }

    /**
     * @throws GuestListRefusee
     */
    public function changerQuota(GuestList $liste, int $quota): void
    {
        if ($quota < 0) {
            throw new GuestListRefusee('Le quota ne peut pas être négatif.');
        }

        $liste->setQuota($quota);
        $this->entityManager->flush();
    }
}
