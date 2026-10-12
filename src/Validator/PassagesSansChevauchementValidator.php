<?php

namespace App\Validator;

use App\Entity\Passage;
use App\Entity\Soiree;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class PassagesSansChevauchementValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof PassagesSansChevauchement) {
            throw new UnexpectedTypeException($constraint, PassagesSansChevauchement::class);
        }
        if (!$value instanceof Soiree) {
            throw new UnexpectedValueException($value, Soiree::class);
        }

        /** @var list<Passage> $passages */
        $passages = array_values($value->getPassages()->toArray());
        usort($passages, static fn (Passage $a, Passage $b): int => $a->getDebut() <=> $b->getDebut());

        foreach ($passages as $i => $passage) {
            foreach (\array_slice($passages, $i + 1) as $autre) {
                if ($passage->chevauche($autre)) {
                    $this->context->buildViolation($constraint->message)
                        ->setParameter('{{ debut }}', $autre->getDebut()->format('H:i'))
                        ->setParameter('{{ fin }}', $autre->getFin()->format('H:i'))
                        ->setParameter('{{ autre_debut }}', $passage->getDebut()->format('H:i'))
                        ->setParameter('{{ autre_fin }}', $passage->getFin()->format('H:i'))
                        ->atPath('passages')
                        ->addViolation();
                }
            }
        }
    }
}
