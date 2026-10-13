<?php

namespace App\Entity;

use App\Enum\SoireeStatut;
use App\Repository\SoireeRepository;
use App\Validator\PassagesSansChevauchement;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * A soirée organised by a collective.
 *
 * Its status is driven by the "soiree" workflow: change it through the
 * workflow, never with setStatut(). Changing the date, jauge, lieu, buvette or
 * artistesPayes publishes App\Event\SoireeParametresModifies.
 */
#[ORM\Entity(repositoryClass: SoireeRepository::class)]
#[ORM\Index(name: 'IDX_SOIREE_COLLECTIVE_DATE', fields: ['collective', 'date'])]
#[ORM\UniqueConstraint(name: 'UNIQ_SOIREE_SLUG', fields: ['slug'])]
#[PassagesSansChevauchement]
class Soiree implements CollectiveScopedInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Collective $collective;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $nom;

    /**
     * Identifies the public page. Set once from the name with a random suffix,
     * so that renaming keeps shared links working and soirées cannot be listed.
     */
    #[ORM\Column(length: 80)]
    private string $slug;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    /** Doors open, on the soirée's date. */
    #[ORM\Column(type: Types::TIME_IMMUTABLE)]
    private \DateTimeImmutable $ouverture;

    /** Closing time; earlier than the opening means after midnight. */
    #[ORM\Column(type: Types::TIME_IMMUTABLE)]
    private \DateTimeImmutable $fermeture;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Lieu $lieu = null;

    /** Maximum number of people admitted. */
    #[ORM\Column(nullable: true)]
    #[Assert\Positive]
    private ?int $jauge = null;

    #[ORM\Column]
    private bool $buvette = false;

    #[ORM\Column]
    private bool $artistesPayes = false;

    /** Fundraising event of the collective: counts against the yearly tax-exempt ones. */
    #[ORM\Column(options: ['default' => false])]
    private bool $soutien = false;

    #[ORM\Column(length: 20)]
    private string $statut = SoireeStatut::Preparation->value;

    /** @var Collection<int, Poste> */
    #[ORM\OneToMany(targetEntity: Poste::class, mappedBy: 'soiree', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $postes;

    /** @var Collection<int, LigneBudget> */
    #[ORM\OneToMany(targetEntity: LigneBudget::class, mappedBy: 'soiree', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['type' => 'ASC', 'id' => 'ASC'])]
    private Collection $lignesBudget;

    /** @var Collection<int, PieceJointe> Managed by App\Soiree\PiecesJointes, which also handles the files. */
    #[ORM\OneToMany(targetEntity: PieceJointe::class, mappedBy: 'soiree')]
    #[ORM\OrderBy(['ajouteeLe' => 'ASC'])]
    private Collection $piecesJointes;

    /** @var Collection<int, Passage> The timetable */
    #[ORM\OneToMany(targetEntity: Passage::class, mappedBy: 'soiree', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['debut' => 'ASC'])]
    #[Assert\Valid]
    private Collection $passages;

    /** @var Collection<int, Booking> */
    #[ORM\OneToMany(targetEntity: Booking::class, mappedBy: 'soiree', cascade: ['persist'])]
    #[Assert\Valid]
    private Collection $bookings;

    public function __construct(Collective $collective, string $nom, \DateTimeImmutable $date, \DateTimeImmutable $ouverture, \DateTimeImmutable $fermeture)
    {
        $this->collective = $collective;
        $this->nom = $nom;
        $base = (string) (new AsciiSlugger('fr'))->slug($nom)->lower()->truncate(60)->trimEnd('-');
        $this->slug = ('' === $base ? 'soiree' : $base).'-'.bin2hex(random_bytes(4));
        $this->date = $date->setTime(0, 0);
        $this->ouverture = $ouverture;
        $this->fermeture = $fermeture;
        $this->postes = new ArrayCollection();
        $this->lignesBudget = new ArrayCollection();
        $this->piecesJointes = new ArrayCollection();
        $this->passages = new ArrayCollection();
        $this->bookings = new ArrayCollection();
    }

    #[Assert\Callback]
    public function validateLieu(ExecutionContextInterface $context): void
    {
        if (null === $this->lieu) {
            return;
        }

        if ($this->lieu->getCollective() !== $this->collective) {
            $context->buildViolation('Ce lieu n\'appartient pas au collectif.')->atPath('lieu')->addViolation();
        }

        if (null !== $this->jauge && $this->jauge > $this->lieu->getCapacite()) {
            $context->buildViolation('La jauge ({{ jauge }}) dépasse la capacité du lieu ({{ capacite }}).')
                ->setParameter('{{ jauge }}', (string) $this->jauge)
                ->setParameter('{{ capacite }}', (string) $this->lieu->getCapacite())
                ->atPath('jauge')
                ->addViolation();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCollective(): Collective
    {
        return $this->collective;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = $nom;

        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(\DateTimeImmutable $date): static
    {
        // Doctrine compares dates by reference: keep the same object when the day is unchanged.
        if ($date->format('Y-m-d') !== $this->date->format('Y-m-d')) {
            $this->date = $date->setTime(0, 0);
        }

        return $this;
    }

    public function getOuverture(): \DateTimeImmutable
    {
        return $this->ouverture;
    }

    public function setOuverture(\DateTimeImmutable $ouverture): static
    {
        if ($ouverture->format('H:i') !== $this->ouverture->format('H:i')) {
            $this->ouverture = $ouverture;
        }

        return $this;
    }

    public function getFermeture(): \DateTimeImmutable
    {
        return $this->fermeture;
    }

    public function setFermeture(\DateTimeImmutable $fermeture): static
    {
        if ($fermeture->format('H:i') !== $this->fermeture->format('H:i')) {
            $this->fermeture = $fermeture;
        }

        return $this;
    }

    public function fermeApresMinuit(): bool
    {
        return $this->fermeture->format('H:i') <= $this->ouverture->format('H:i');
    }

    public function getLieu(): ?Lieu
    {
        return $this->lieu;
    }

    public function setLieu(?Lieu $lieu): static
    {
        $this->lieu = $lieu;

        return $this;
    }

    public function getJauge(): ?int
    {
        return $this->jauge;
    }

    public function setJauge(?int $jauge): static
    {
        $this->jauge = $jauge;

        return $this;
    }

    public function hasBuvette(): bool
    {
        return $this->buvette;
    }

    public function setBuvette(bool $buvette): static
    {
        $this->buvette = $buvette;

        return $this;
    }

    public function hasArtistesPayes(): bool
    {
        return $this->artistesPayes;
    }

    public function setArtistesPayes(bool $artistesPayes): static
    {
        $this->artistesPayes = $artistesPayes;

        return $this;
    }

    public function isSoutien(): bool
    {
        return $this->soutien;
    }

    public function setSoutien(bool $soutien): static
    {
        $this->soutien = $soutien;

        return $this;
    }

    /**
     * Marking of the "soiree" workflow.
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
        $this->statut = SoireeStatut::from($statut)->value;
    }

    public function getStatutEnum(): SoireeStatut
    {
        return SoireeStatut::from($this->statut);
    }

    /**
     * @return Collection<int, Poste>
     */
    public function getPostes(): Collection
    {
        return $this->postes;
    }

    /**
     * @return Collection<int, LigneBudget>
     */
    public function getLignesBudget(): Collection
    {
        return $this->lignesBudget;
    }

    /**
     * @return Collection<int, PieceJointe>
     */
    public function getPiecesJointes(): Collection
    {
        return $this->piecesJointes;
    }

    /**
     * @return Collection<int, Passage>
     */
    public function getPassages(): Collection
    {
        return $this->passages;
    }

    /**
     * @return Collection<int, Booking>
     */
    public function getBookings(): Collection
    {
        return $this->bookings;
    }
}
