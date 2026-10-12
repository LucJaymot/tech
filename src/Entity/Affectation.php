<?php

namespace App\Entity;

use App\Repository\AffectationRepository;
use App\Validator\AffectationSansChevauchement;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * A volunteer assigned to a créneau. A volunteer cannot be on two créneaux
 * that overlap, whatever the soirée.
 */
#[ORM\Entity(repositoryClass: AffectationRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_AFFECTATION_CRENEAU_BENEVOLE', fields: ['creneau', 'benevole'])]
#[UniqueEntity(fields: ['creneau', 'benevole'], message: 'Ce bénévole est déjà sur ce créneau.')]
#[AffectationSansChevauchement]
class Affectation implements CollectiveScopedInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'affectations')]
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
        $this->creneau = $creneau;
        $this->benevole = $benevole;
        $this->collective = $creneau->getCollective();

        $creneau->getAffectations()->add($this);
    }

    #[Assert\Callback]
    public function validateBenevole(ExecutionContextInterface $context): void
    {
        if ($this->benevole->getCollective() !== $this->collective) {
            $context->buildViolation('Ce bénévole n\'est pas dans le collectif.')->atPath('benevole')->addViolation();
        }
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
