<?php

namespace App\Entity;

use App\Repository\PosteRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A volunteer role during a soirée (bar, door, sound…), staffed through its créneaux.
 */
#[ORM\Entity(repositoryClass: PosteRepository::class)]
class Poste implements CollectiveScopedInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'postes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Soiree $soiree;

    /** Copied from the soirée, for the collective filter. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Collective $collective;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private string $intitule;

    /** @var Collection<int, Creneau> */
    #[ORM\OneToMany(targetEntity: Creneau::class, mappedBy: 'poste', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['debut' => 'ASC', 'id' => 'ASC'])]
    #[Assert\Valid]
    private Collection $creneaux;

    public function __construct(Soiree $soiree, string $intitule)
    {
        $this->soiree = $soiree;
        $this->collective = $soiree->getCollective();
        $this->intitule = $intitule;
        $this->creneaux = new ArrayCollection();

        $soiree->getPostes()->add($this);
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

    public function getIntitule(): string
    {
        return $this->intitule;
    }

    /**
     * @return Collection<int, Creneau>
     */
    public function getCreneaux(): Collection
    {
        return $this->creneaux;
    }
}
