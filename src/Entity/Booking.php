<?php

namespace App\Entity;

use App\Booking\Hospitalite;
use App\Booking\Rider;
use App\Doctrine\Type\HospitaliteType;
use App\Doctrine\Type\RiderType;
use App\Enum\BookingStatut;
use App\Enum\ModeRemuneration;
use App\Repository\BookingRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * An artist booked on a soirée.
 *
 * Its status is driven by the "booking" workflow: change it through the
 * workflow, never with setStatut(); each transition is kept in $transitions.
 */
#[ORM\Entity(repositoryClass: BookingRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_BOOKING_SOIREE_ARTISTE', fields: ['soiree', 'artiste'])]
#[UniqueEntity(fields: ['soiree', 'artiste'], message: 'Cet artiste est déjà programmé sur cette soirée.')]
class Booking implements CollectiveScopedInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'bookings')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Soiree $soiree;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Artiste $artiste;

    /** Copied from the soirée, for the collective filter. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Collective $collective;

    /** In cents. */
    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private int $cachet;

    #[ORM\Column(length: 20, enumType: ModeRemuneration::class)]
    private ModeRemuneration $modeRemuneration;

    /** Null until the artist is placed in the timetable. */
    #[ORM\ManyToOne(inversedBy: 'bookings')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Passage $passage = null;

    #[ORM\Column(length: 20)]
    private string $statut = BookingStatut::Contacte->value;

    #[ORM\Column(type: RiderType::NAME, options: ['jsonb' => true])]
    #[Assert\Valid]
    private Rider $rider;

    #[ORM\Column(type: HospitaliteType::NAME, options: ['jsonb' => true])]
    #[Assert\Valid]
    private Hospitalite $hospitalite;

    /** The fee line generated once the booking is confirmed. */
    #[ORM\OneToOne(mappedBy: 'booking')]
    private ?LigneBudget $ligneBudget = null;

    /** @var Collection<int, BookingTransition> */
    #[ORM\OneToMany(targetEntity: BookingTransition::class, mappedBy: 'booking', cascade: ['persist'])]
    #[ORM\OrderBy(['effectueeLe' => 'ASC', 'id' => 'ASC'])]
    private Collection $transitions;

    public function __construct(Soiree $soiree, Artiste $artiste, int $cachet, ModeRemuneration $modeRemuneration)
    {
        $this->soiree = $soiree;
        $this->artiste = $artiste;
        $this->collective = $soiree->getCollective();
        $this->cachet = $cachet;
        $this->modeRemuneration = $modeRemuneration;
        $this->rider = new Rider();
        $this->hospitalite = new Hospitalite();
        $this->transitions = new ArrayCollection();

        $soiree->getBookings()->add($this);
    }

    #[Assert\Callback]
    public function validateRelations(ExecutionContextInterface $context): void
    {
        if ($this->artiste->getCollective() !== $this->collective) {
            $context->buildViolation('Cet artiste n\'est pas dans le carnet du collectif.')->atPath('artiste')->addViolation();
        }
        if (null !== $this->passage && $this->passage->getSoiree() !== $this->soiree) {
            $context->buildViolation('Ce passage appartient à une autre soirée.')->atPath('passage')->addViolation();
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

    public function getArtiste(): Artiste
    {
        return $this->artiste;
    }

    public function getCollective(): Collective
    {
        return $this->collective;
    }

    /** In cents. */
    public function getCachet(): int
    {
        return $this->cachet;
    }

    public function setCachet(int $cachet): static
    {
        $this->cachet = $cachet;

        return $this;
    }

    public function getModeRemuneration(): ModeRemuneration
    {
        return $this->modeRemuneration;
    }

    public function setModeRemuneration(ModeRemuneration $modeRemuneration): static
    {
        $this->modeRemuneration = $modeRemuneration;

        return $this;
    }

    /** Used by the "booking" workflow guards. */
    public function estGuso(): bool
    {
        return ModeRemuneration::Guso === $this->modeRemuneration;
    }

    public function getPassage(): ?Passage
    {
        return $this->passage;
    }

    public function setPassage(?Passage $passage): static
    {
        $this->passage?->getBookings()->removeElement($this);
        $this->passage = $passage;
        $passage?->getBookings()->add($this);

        return $this;
    }

    /**
     * Marking of the "booking" workflow.
     */
    public function getStatut(): string
    {
        return $this->statut;
    }

    /**
     * @internal for the workflow marking store only
     */
    public function setStatut(string $statut): void
    {
        $this->statut = BookingStatut::from($statut)->value;
    }

    public function getStatutEnum(): BookingStatut
    {
        return BookingStatut::from($this->statut);
    }

    public function getRider(): Rider
    {
        return $this->rider;
    }

    public function setRider(Rider $rider): static
    {
        $this->rider = $rider;

        return $this;
    }

    public function getHospitalite(): Hospitalite
    {
        return $this->hospitalite;
    }

    public function setHospitalite(Hospitalite $hospitalite): static
    {
        $this->hospitalite = $hospitalite;

        return $this;
    }

    public function getLigneBudget(): ?LigneBudget
    {
        return $this->ligneBudget;
    }

    /**
     * @internal for LigneBudget::pourCachet() and App\EventListener\CachetsBudgetListener
     */
    public function setLigneBudget(?LigneBudget $ligneBudget): void
    {
        $this->ligneBudget = $ligneBudget;
    }

    /**
     * Confirmed or further: its fee belongs in the budget.
     */
    public function estConfirme(): bool
    {
        return \in_array($this->getStatutEnum(), [BookingStatut::Confirme, BookingStatut::ContratSigne, BookingStatut::DeclareGuso, BookingStatut::Paye], true);
    }

    /**
     * @return Collection<int, BookingTransition>
     */
    public function getTransitions(): Collection
    {
        return $this->transitions;
    }
}
