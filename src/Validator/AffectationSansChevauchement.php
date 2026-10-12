<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * A volunteer cannot be assigned to two créneaux that overlap, on any soirée.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AffectationSansChevauchement extends Constraint
{
    public string $message = '{{ benevole }} est déjà sur « {{ poste }} » de {{ debut }} à {{ fin }}, qui chevauche ce créneau.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
