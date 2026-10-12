<?php

namespace App\Entity;

use App\Enum\LigneBudgetType;
use App\Repository\LigneBudgetRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A line of a soirée's forecast budget. Amounts are in cents.
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

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $libelle;

    #[ORM\Column(length: 20, enumType: LigneBudgetType::class)]
    private LigneBudgetType $type;

    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private int $montantPrevu;

    public function __construct(Soiree $soiree, string $libelle, LigneBudgetType $type, int $montantPrevu)
    {
        $this->soiree = $soiree;
        $this->collective = $soiree->getCollective();
        $this->libelle = $libelle;
        $this->type = $type;
        $this->montantPrevu = $montantPrevu;

        $soiree->getLignesBudget()->add($this);
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

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function getType(): LigneBudgetType
    {
        return $this->type;
    }

    /** In cents. */
    public function getMontantPrevu(): int
    {
        return $this->montantPrevu;
    }
}
