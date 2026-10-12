<?php

namespace App\Entity;

use App\Repository\PieceJointeRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A file attached to a soirée. The file itself lives in the "pieces_jointes"
 * Flysystem storage under $chemin; see App\Soiree\PiecesJointes.
 */
#[ORM\Entity(repositoryClass: PieceJointeRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_PIECE_JOINTE_CHEMIN', fields: ['chemin'])]
class PieceJointe implements CollectiveScopedInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'piecesJointes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Soiree $soiree;

    /** Copied from the soirée, for the collective filter. */
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
    private ?User $ajouteePar;

    #[ORM\Column]
    private \DateTimeImmutable $ajouteeLe;

    public function __construct(Soiree $soiree, string $nomOriginal, string $chemin, string $typeMime, int $taille, ?User $ajouteePar)
    {
        $this->soiree = $soiree;
        $this->collective = $soiree->getCollective();
        $this->nomOriginal = $nomOriginal;
        $this->chemin = $chemin;
        $this->typeMime = $typeMime;
        $this->taille = $taille;
        $this->ajouteePar = $ajouteePar;
        $this->ajouteeLe = new \DateTimeImmutable();

        $soiree->getPiecesJointes()->add($this);
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

    public function getAjouteePar(): ?User
    {
        return $this->ajouteePar;
    }

    public function getAjouteeLe(): \DateTimeImmutable
    {
        return $this->ajouteeLe;
    }
}
