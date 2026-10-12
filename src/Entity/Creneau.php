<?php

namespace App\Entity;

use App\Repository\CreneauRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A time slot of a poste, needing a number of volunteers.
 * Times are full date-times, so slots after midnight need no special case.
 */
#[ORM\Entity(repositoryClass: CreneauRepository::class)]
#[ORM\Index(name: 'IDX_CRENEAU_SOIREE_DEBUT', fields: ['soiree', 'debut'])]
class Creneau implements CollectiveScopedInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'creneaux')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Poste $poste;

    /** Copied from the poste, for per-soirée queries. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Soiree $soiree;

    /** Copied from the poste, for the collective filter. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Collective $collective;

    #[ORM\Column]
    private \DateTimeImmutable $debut;

    #[ORM\Column]
    #[Assert\GreaterThan(propertyPath: 'debut', message: 'La fin du créneau doit être après son début.')]
    private \DateTimeImmutable $fin;

    #[ORM\Column]
    #[Assert\Positive]
    private int $nombreRequis;

    /** @var Collection<int, Affectation> */
    #[ORM\OneToMany(targetEntity: Affectation::class, mappedBy: 'creneau')]
    private Collection $affectations;

    public function __construct(Poste $poste, \DateTimeImmutable $debut, \DateTimeImmutable $fin, int $nombreRequis = 1)
    {
        $this->poste = $poste;
        $this->soiree = $poste->getSoiree();
        $this->collective = $poste->getCollective();
        $this->debut = $debut;
        $this->fin = $fin;
        $this->nombreRequis = $nombreRequis;
        $this->affectations = new ArrayCollection();

        $poste->getCreneaux()->add($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPoste(): Poste
    {
        return $this->poste;
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

    public function getNombreRequis(): int
    {
        return $this->nombreRequis;
    }

    /**
     * Whether the two slots share some time. Back-to-back slots do not overlap.
     */
    public function chevauche(self $autre): bool
    {
        return $this->debut < $autre->fin && $autre->debut < $this->fin;
    }

    /**
     * @return Collection<int, Affectation>
     */
    public function getAffectations(): Collection
    {
        return $this->affectations;
    }
}
