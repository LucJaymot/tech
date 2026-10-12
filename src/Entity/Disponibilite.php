<?php

namespace App\Entity;

use App\Repository\DisponibiliteRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A volunteer says they can take a créneau. Declared from their personal link.
 */
#[ORM\Entity(repositoryClass: DisponibiliteRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_DISPONIBILITE_CRENEAU_BENEVOLE', fields: ['creneau', 'benevole'])]
class Disponibilite implements CollectiveScopedInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Creneau $creneau;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Benevole $benevole;

    /** Copied from the créneau, for the collective filter. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Collective $collective;

    public function __construct(Creneau $creneau, Benevole $benevole)
    {
        if ($benevole->getCollective() !== $creneau->getCollective()) {
            throw new \InvalidArgumentException('The volunteer and the créneau belong to different collectives.');
        }

        $this->creneau = $creneau;
        $this->benevole = $benevole;
        $this->collective = $creneau->getCollective();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCreneau(): Creneau
    {
        return $this->creneau;
    }

    public function getBenevole(): Benevole
    {
        return $this->benevole;
    }

    public function getCollective(): Collective
    {
        return $this->collective;
    }
}
