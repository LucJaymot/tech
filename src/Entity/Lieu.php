<?php

namespace App\Entity;

use App\Repository\LieuRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A venue of a collective, reused from one soirée to the next.
 */
#[ORM\Entity(repositoryClass: LieuRepository::class)]
class Lieu implements CollectiveScopedInterface
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

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    private string $adresse;

    /** How to get there and in: entrance, parking, door code, accessibility. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $acces = null;

    #[ORM\Column]
    #[Assert\Positive]
    private int $capacite;

    #[ORM\Column]
    private bool $amenagePourPublic = false;

    public function __construct(Collective $collective, string $nom, string $adresse, int $capacite)
    {
        $this->collective = $collective;
        $this->nom = $nom;
        $this->adresse = $adresse;
        $this->capacite = $capacite;
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

    public function getAdresse(): string
    {
        return $this->adresse;
    }

    public function setAdresse(string $adresse): static
    {
        $this->adresse = $adresse;

        return $this;
    }

    public function getAcces(): ?string
    {
        return $this->acces;
    }

    public function setAcces(?string $acces): static
    {
        $this->acces = $acces;

        return $this;
    }

    public function getCapacite(): int
    {
        return $this->capacite;
    }

    public function setCapacite(int $capacite): static
    {
        $this->capacite = $capacite;

        return $this;
    }

    public function isAmenagePourPublic(): bool
    {
        return $this->amenagePourPublic;
    }

    public function setAmenagePourPublic(bool $amenagePourPublic): static
    {
        $this->amenagePourPublic = $amenagePourPublic;

        return $this;
    }
}
