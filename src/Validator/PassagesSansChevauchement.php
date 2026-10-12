<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * The passages of a soirée's timetable must not overlap.
 * Artists playing together share one passage (a B2B) instead.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class PassagesSansChevauchement extends Constraint
{
    public string $message = 'Le passage de {{ debut }} à {{ fin }} chevauche celui de {{ autre_debut }} à {{ autre_fin }}. Pour un B2B, placez les artistes sur le même passage.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
