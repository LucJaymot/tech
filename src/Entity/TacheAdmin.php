<?php

namespace App\Entity;

use App\Obligations\Regle;
use App\Repository\TacheAdminRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An administrative task of a soirée's rétroplanning, generated from a Regle
 * by App\Obligations\RetroplanningGenerator, which never changes a done task.
 */
#[ORM\Entity(repositoryClass: TacheAdminRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_TACHE_ADMIN_SOIREE_CODE', fields: ['soiree', 'code'])]
#[ORM\Index(name: 'IDX_TACHE_ADMIN_ECHEANCE', fields: ['echeance'])]
class TacheAdmin implements CollectiveScopedInterface
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

    /** The Regle it comes from. */
    #[ORM\Column(length: 60)]
    private string $code;

    #[ORM\Column(length: 255)]
    private string $libelle;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $echeance;

    #[ORM\Column(length: 255)]
    private string $source;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $verifieeLe;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $faiteLe = null;

    public function __construct(Soiree $soiree, Regle $regle)
    {
        $this->collective = $soiree->getCollective();
        $this->soiree = $soiree;
        $this->code = $regle->code;
        $this->appliquer($regle);
    }

    /**
     * Copies the rule's current wording, source and due date.
     */
    public function appliquer(Regle $regle): void
    {
        $this->libelle = $regle->libelle;
        $this->source = $regle->source;
        $this->verifieeLe = $regle->verifieeLe;
        $echeance = $regle->echeance($this->soiree);
        // Doctrine compares dates by reference: keep the same object when the day is unchanged.
        if (!isset($this->echeance) || $echeance->format('Y-m-d') !== $this->echeance->format('Y-m-d')) {
            $this->echeance = $echeance;
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

    public function getSoiree(): Soiree
    {
        return $this->soiree;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function getEcheance(): \DateTimeImmutable
    {
        return $this->echeance;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getVerifieeLe(): \DateTimeImmutable
    {
        return $this->verifieeLe;
    }

    public function getFaiteLe(): ?\DateTimeImmutable
    {
        return $this->faiteLe;
    }

    public function estFaite(): bool
    {
        return null !== $this->faiteLe;
    }

    public function cocher(\DateTimeImmutable $le): void
    {
        $this->faiteLe ??= $le;
    }

    public function decocher(): void
    {
        $this->faiteLe = null;
    }
}
