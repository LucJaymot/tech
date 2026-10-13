<?php

namespace App\Entity;

use App\Repository\DebriefingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What one member has to say about a soirée once it is over: one free text
 * per member and per soirée.
 */
#[ORM\Entity(repositoryClass: DebriefingRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_DEBRIEFING_SOIREE_AUTEUR', fields: ['soiree', 'auteur'])]
class Debriefing implements CollectiveScopedInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Collective $collective;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Soiree $soiree;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $auteur;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: 'Écrivez quelques mots.')]
    #[Assert\Length(max: 5000)]
    private string $texte = '';

    #[ORM\Column]
    private \DateTimeImmutable $modifieLe;

    public function __construct(Soiree $soiree, User $auteur)
    {
        $this->collective = $soiree->getCollective();
        $this->soiree = $soiree;
        $this->auteur = $auteur;
        $this->modifieLe = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCollective(): Collective
    {
        return $this->collective;
    }

    public function getSoiree(): Soiree
    {
        return $this->soiree;
    }

    public function getAuteur(): User
    {
        return $this->auteur;
    }

    public function getTexte(): string
    {
        return $this->texte;
    }

    public function setTexte(string $texte, \DateTimeImmutable $le): static
    {
        $this->texte = trim($texte);
        $this->modifieLe = $le;

        return $this;
    }

    public function getModifieLe(): \DateTimeImmutable
    {
        return $this->modifieLe;
    }
}
