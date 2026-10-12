<?php

namespace App\Entity;

use App\Repository\PosteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A volunteer position to fill during a soirée (bar, door, sound…).
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

    #[ORM\Column(type: Types::TIME_IMMUTABLE)]
    private \DateTimeImmutable $debut;

    #[ORM\Column(type: Types::TIME_IMMUTABLE)]
    private \DateTimeImmutable $fin;

    #[ORM\Column]
    #[Assert\Positive]
    private int $nombrePersonnes;

    public function __construct(Soiree $soiree, string $intitule, \DateTimeImmutable $debut, \DateTimeImmutable $fin, int $nombrePersonnes = 1)
    {
        $this->soiree = $soiree;
        $this->collective = $soiree->getCollective();
        $this->intitule = $intitule;
        $this->debut = $debut;
        $this->fin = $fin;
        $this->nombrePersonnes = $nombrePersonnes;

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

    public function getDebut(): \DateTimeImmutable
    {
        return $this->debut;
    }

    public function getFin(): \DateTimeImmutable
    {
        return $this->fin;
    }

    public function getNombrePersonnes(): int
    {
        return $this->nombrePersonnes;
    }
}
