<?php

namespace App\Entity;

use App\Repository\BookingTransitionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One step of a booking's history, recorded by App\EventListener\BookingTransitionListener.
 */
#[ORM\Entity(repositoryClass: BookingTransitionRepository::class, readOnly: true)]
class BookingTransition implements CollectiveScopedInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'transitions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Booking $booking;

    /** Copied from the booking, for the collective filter. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Collective $collective;

    #[ORM\Column(length: 30)]
    private string $transition;

    #[ORM\Column(length: 20)]
    private string $depuis;

    #[ORM\Column(length: 20)]
    private string $vers;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $effectueePar;

    #[ORM\Column]
    private \DateTimeImmutable $effectueeLe;

    public function __construct(Booking $booking, string $transition, string $depuis, string $vers, ?User $effectueePar)
    {
        $this->booking = $booking;
        $this->collective = $booking->getCollective();
        $this->transition = $transition;
        $this->depuis = $depuis;
        $this->vers = $vers;
        $this->effectueePar = $effectueePar;
        $this->effectueeLe = new \DateTimeImmutable();

        $booking->getTransitions()->add($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBooking(): Booking
    {
        return $this->booking;
    }

    public function getCollective(): Collective
    {
        return $this->collective;
    }

    public function getTransition(): string
    {
        return $this->transition;
    }

    public function getDepuis(): string
    {
        return $this->depuis;
    }

    public function getVers(): string
    {
        return $this->vers;
    }

    public function getEffectueePar(): ?User
    {
        return $this->effectueePar;
    }

    public function getEffectueeLe(): \DateTimeImmutable
    {
        return $this->effectueeLe;
    }
}
