<?php

namespace App\Entity;

use App\Repository\GuestListRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * The guests of one owner on a soirée: a booked artist or a member.
 * The quota counts people, guests and their companions together.
 */
#[ORM\Entity(repositoryClass: GuestListRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_GUEST_LIST_BOOKING', fields: ['booking'])]
#[ORM\UniqueConstraint(name: 'UNIQ_GUEST_LIST_SOIREE_MEMBRE', fields: ['soiree', 'membre'])]
class GuestList implements CollectiveScopedInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Soiree $soiree;

    /** Copied from the soirée, for the collective filter. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Collective $collective;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?Booking $booking = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?User $membre = null;

    /** Maximum number of people, companions included. */
    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private int $quota;

    /** @var Collection<int, Invite> */
    #[ORM\OneToMany(targetEntity: Invite::class, mappedBy: 'guestList')]
    #[ORM\OrderBy(['nom' => 'ASC'])]
    private Collection $invites;

    private function __construct(Soiree $soiree, int $quota)
    {
        $this->soiree = $soiree;
        $this->collective = $soiree->getCollective();
        $this->quota = $quota;
        $this->invites = new ArrayCollection();
    }

    public static function pourBooking(Booking $booking, int $quota): self
    {
        $liste = new self($booking->getSoiree(), $quota);
        $liste->booking = $booking;

        return $liste;
    }

    public static function pourMembre(Soiree $soiree, User $membre, int $quota): self
    {
        $liste = new self($soiree, $quota);
        $liste->membre = $membre;

        return $liste;
    }

    #[Assert\Callback]
    public function validateProprietaire(ExecutionContextInterface $context): void
    {
        if ((null === $this->booking) === (null === $this->membre)) {
            $context->buildViolation('Une guest list appartient soit à un booking, soit à un membre.')->addViolation();
        }
        if (null !== $this->booking && $this->booking->getSoiree() !== $this->soiree) {
            $context->buildViolation('Ce booking est sur une autre soirée.')->atPath('booking')->addViolation();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSoiree(): Soiree
    {
        return $this->soiree;
    }

    public function getCollective(): Collective
    {
        return $this->collective;
    }

    public function getBooking(): ?Booking
    {
        return $this->booking;
    }

    public function getMembre(): ?User
    {
        return $this->membre;
    }

    /**
     * The artist's or member's name, as shown at the door.
     */
    public function getProprietaire(): string
    {
        return $this->booking?->getArtiste()->getNom() ?? $this->membre?->getDisplayName() ?? '';
    }

    public function getQuota(): int
    {
        return $this->quota;
    }

    public function setQuota(int $quota): static
    {
        $this->quota = $quota;

        return $this;
    }

    /**
     * @return Collection<int, Invite>
     */
    public function getInvites(): Collection
    {
        return $this->invites;
    }

    /**
     * People already on the list, companions included.
     */
    public function personnes(): int
    {
        return array_sum($this->invites->map(static fn (Invite $i): int => $i->personnes())->toArray());
    }

    public function placesRestantes(): int
    {
        return max(0, $this->quota - $this->personnes());
    }
}
