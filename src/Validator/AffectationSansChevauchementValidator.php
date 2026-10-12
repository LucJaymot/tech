<?php

namespace App\Validator;

use App\Entity\Affectation;
use App\Repository\AffectationRepository;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class AffectationSansChevauchementValidator extends ConstraintValidator
{
    public function __construct(
        private readonly AffectationRepository $affectations,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof AffectationSansChevauchement) {
            throw new UnexpectedTypeException($constraint, AffectationSansChevauchement::class);
        }
        if (!$value instanceof Affectation) {
            throw new UnexpectedValueException($value, Affectation::class);
        }

        // A volunteer not saved yet has no other assignment.
        if (null === $value->getBenevole()->getId()) {
            return;
        }

        $creneau = $value->getCreneau();
        foreach ($this->affectations->findChevauchements($value->getBenevole(), $creneau->getDebut(), $creneau->getFin(), $value) as $autre) {
            if ($autre->getCreneau() === $creneau) {
                continue; // Same créneau twice: reported by UniqueEntity.
            }

            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ benevole }}', $value->getBenevole()->getNom())
                ->setParameter('{{ poste }}', $autre->getCreneau()->getPoste()->getIntitule())
                ->setParameter('{{ debut }}', $autre->getCreneau()->getDebut()->format('d/m H:i'))
                ->setParameter('{{ fin }}', $autre->getCreneau()->getFin()->format('H:i'))
                ->atPath('creneau')
                ->addViolation();
        }
    }
}
