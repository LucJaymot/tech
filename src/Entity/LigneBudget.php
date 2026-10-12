<?php

namespace App\Entity;

use App\Budget\LigneMontants;
use App\Enum\BookingStatut;
use App\Enum\CategorieBudget;
use App\Enum\LigneBudgetType;
use App\Repository\LigneBudgetRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * A line of a soirée's budget. Amounts are in cents; the actual amount stays
 * null until it is known.
 *
 * Fee lines are generated from confirmed bookings (see
 * App\EventListener\CachetsBudgetListener) and cannot be changed by hand.
 */
#[ORM\Entity(repositoryClass: LigneBudgetRepository::class)]
class LigneBudget implements CollectiveScopedInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'lignesBudget')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Soiree $soiree;

    /** Copied from the soirée, for the collective filter. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Collective $collective;

    #[ORM\Column(length: 20, enumType: LigneBudgetType::class)]
    private LigneBudgetType $type;

    #[ORM\Column(length: 20, enumType: CategorieBudget::class)]
    private CategorieBudget $categorie;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $libelle;

    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private int $montantPrevu;

    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $montantReel = null;

    /** Set for fee lines generated from a booking. */
    #[ORM\OneToOne(inversedBy: 'ligneBudget')]
    #[ORM\JoinColumn(unique: true, onDelete: 'CASCADE')]
    private ?Booking $booking = null;

    /** @var Collection<int, Justificatif> Managed by App\Budget\Justificatifs, which also handles the files. */
    #[ORM\OneToMany(targetEntity: Justificatif::class, mappedBy: 'ligneBudget')]
    #[ORM\OrderBy(['ajouteLe' => 'ASC'])]
    private Collection $justificatifs;

    public function __construct(Soiree $soiree, LigneBudgetType $type, CategorieBudget $categorie, string $libelle, int $montantPrevu)
    {
        $this->soiree = $soiree;
        $this->collective = $soiree->getCollective();
        $this->type = $type;
        $this->categorie = $categorie;
        $this->libelle = $libelle;
        $this->montantPrevu = $montantPrevu;
        $this->justificatifs = new ArrayCollection();

        $soiree->getLignesBudget()->add($this);
    }

    /**
     * The fee line of a confirmed booking.
     *
     * @internal for App\EventListener\CachetsBudgetListener
     */
    public static function pourCachet(Booking $booking): self
    {
        $ligne = new self($booking->getSoiree(), LigneBudgetType::Depense, CategorieBudget::Cachets, '', 0);
        $ligne->booking = $booking;
        $booking->setLigneBudget($ligne);
        $ligne->synchroniserCachet();

        return $ligne;
    }

    /**
     * Copies the booking's fee: forecast amount, and actual amount once paid.
     *
     * @internal for App\EventListener\CachetsBudgetListener
     *
     * @return bool whether something changed
     */
    public function synchroniserCachet(): bool
    {
        if (null === $this->booking) {
            throw new \LogicException('Only fee lines follow a booking.');
        }

        $avant = [$this->libelle, $this->montantPrevu, $this->montantReel];
        $this->libelle = mb_substr('Cachet · '.$this->booking->getArtiste()->getNom(), 0, 120);
        $this->montantPrevu = $this->booking->getCachet();
        $this->montantReel = BookingStatut::Paye === $this->booking->getStatutEnum() ? $this->booking->getCachet() : null;

        return $avant !== [$this->libelle, $this->montantPrevu, $this->montantReel];
    }

    #[Assert\Callback]
    public function validateCategorie(ExecutionContextInterface $context): void
    {
        if (!\in_array($this->type, $this->categorie->types(), true)) {
            $context->buildViolation('La catégorie « {{ categorie }} » ne s\'applique pas à ce type de ligne.')
                ->setParameter('{{ categorie }}', $this->categorie->label())
                ->atPath('categorie')
                ->addViolation();
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

    public function getType(): LigneBudgetType
    {
        return $this->type;
    }

    public function getCategorie(): CategorieBudget
    {
        return $this->categorie;
    }

    public function setCategorie(CategorieBudget $categorie): static
    {
        $this->assertModifiable();
        $this->categorie = $categorie;

        return $this;
    }

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function setLibelle(string $libelle): static
    {
        $this->assertModifiable();
        $this->libelle = $libelle;

        return $this;
    }

    /** In cents. */
    public function getMontantPrevu(): int
    {
        return $this->montantPrevu;
    }

    public function setMontantPrevu(int $montantPrevu): static
    {
        $this->assertModifiable();
        $this->montantPrevu = $montantPrevu;

        return $this;
    }

    /** In cents, null while unknown. */
    public function getMontantReel(): ?int
    {
        return $this->montantReel;
    }

    public function setMontantReel(?int $montantReel): static
    {
        $this->assertModifiable();
        $this->montantReel = $montantReel;

        return $this;
    }

    public function getBooking(): ?Booking
    {
        return $this->booking;
    }

    /**
     * Generated from a booking, hence read-only.
     */
    public function estGeneree(): bool
    {
        return null !== $this->booking;
    }

    public function montants(): LigneMontants
    {
        return new LigneMontants($this->type, $this->categorie, $this->montantPrevu, $this->montantReel);
    }

    /**
     * @return Collection<int, Justificatif>
     */
    public function getJustificatifs(): Collection
    {
        return $this->justificatifs;
    }

    private function assertModifiable(): void
    {
        if ($this->estGeneree()) {
            throw new \LogicException('A fee line follows its booking and cannot be changed by hand.');
        }
    }
}
