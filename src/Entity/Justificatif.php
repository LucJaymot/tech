<?php

namespace App\Entity;

use App\Repository\JustificatifRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A receipt or invoice backing a budget line. The file itself lives in the
 * "justificatifs" Flysystem storage under $chemin; see App\Budget\Justificatifs.
 */
#[ORM\Entity(repositoryClass: JustificatifRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_JUSTIFICATIF_CHEMIN', fields: ['chemin'])]
class Justificatif implements CollectiveScopedInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'justificatifs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private LigneBudget $ligneBudget;

    /** Copied from the line, for the collective filter. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Collective $collective;

    #[ORM\Column(length: 255)]
    private string $nomOriginal;

    /** Key in the storage, never derived from the original name. */
    #[ORM\Column(length: 255)]
    private string $chemin;

    #[ORM\Column(length: 100)]
    private string $typeMime;

    /** In bytes. */
    #[ORM\Column]
    private int $taille;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $ajoutePar;

    #[ORM\Column]
    private \DateTimeImmutable $ajouteLe;

    public function __construct(LigneBudget $ligneBudget, string $nomOriginal, string $chemin, string $typeMime, int $taille, ?User $ajoutePar)
    {
        $this->ligneBudget = $ligneBudget;
        $this->collective = $ligneBudget->getCollective();
        $this->nomOriginal = $nomOriginal;
        $this->chemin = $chemin;
        $this->typeMime = $typeMime;
        $this->taille = $taille;
        $this->ajoutePar = $ajoutePar;
        $this->ajouteLe = new \DateTimeImmutable();

        $ligneBudget->getJustificatifs()->add($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLigneBudget(): LigneBudget
    {
        return $this->ligneBudget;
    }

    public function getCollective(): Collective
    {
        return $this->collective;
    }

    public function getNomOriginal(): string
    {
        return $this->nomOriginal;
    }

    public function getChemin(): string
    {
        return $this->chemin;
    }

    public function getTypeMime(): string
    {
        return $this->typeMime;
    }

    public function getTaille(): int
    {
        return $this->taille;
    }

    public function getAjoutePar(): ?User
    {
        return $this->ajoutePar;
    }

    public function getAjouteLe(): \DateTimeImmutable
    {
        return $this->ajouteLe;
    }
}
