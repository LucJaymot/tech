<?php

namespace App\Entity;

use App\Repository\PassageRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A slot of a soirée's timetable. Shared by several bookings, it is a B2B.
 * Times are full date-times, so slots after midnight need no special case.
 */
#[ORM\Entity(repositoryClass: PassageRepository::class)]
class Passage implements CollectiveScopedInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'passages')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Soiree $soiree;

    /** Copied from the soirée, for the collective filter. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Collective $collective;

    #[ORM\Column]
    private \DateTimeImmutable $debut;

    #[ORM\Column]
    #[Assert\GreaterThan(propertyPath: 'debut', message: 'La fin du passage doit être après son début.')]
    private \DateTimeImmutable $fin;

    /** @var Collection<int, Booking> */
    #[ORM\OneToMany(targetEntity: Booking::class, mappedBy: 'passage')]
    private Collection $bookings;

    public function __construct(Soiree $soiree, \DateTimeImmutable $debut, \DateTimeImmutable $fin)
    {
        $this->soiree = $soiree;
        $this->collective = $soiree->getCollective();
        $this->debut = $debut;
        $this->fin = $fin;
        $this->bookings = new ArrayCollection();

        $soiree->getPassages()->add($this);
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

    public function getDebut(): \DateTimeImmutable
    {
        return $this->debut;
    }

    public function getFin(): \DateTimeImmutable
    {
        return $this->fin;
    }

    public function deplacer(\DateTimeImmutable $debut, \DateTimeImmutable $fin): void
    {
        $this->debut = $debut;
        $this->fin = $fin;
    }

    /**
     * Whether the two slots share some time. Back-to-back slots do not overlap.
     */
    public function chevauche(self $autre): bool
    {
        return $this->debut < $autre->fin && $autre->debut < $this->fin;
    }

    /**
     * @return Collection<int, Booking>
     */
    public function getBookings(): Collection
    {
        return $this->bookings;
    }

    public function estB2B(): bool
    {
        return $this->bookings->count() > 1;
    }
}
