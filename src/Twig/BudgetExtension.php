<?php

namespace App\Twig;

use App\Budget\Centimes;
use Twig\Attribute\AsTwigFilter;

final class BudgetExtension
{
    /**
     * {{ 125050|euros }} → "1 250,50 €".
     */
    #[AsTwigFilter('euros')]
    public function euros(int $centimes): string
    {
        return Centimes::formater($centimes);
    }
}
