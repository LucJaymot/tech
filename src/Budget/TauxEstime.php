<?php

namespace App\Budget;

use App\Enum\CategorieBudget;

/**
 * A rate applied to one category of the budget, e.g. the 3,5 % tax on ticket
 * sales. Configured in config/packages/budget.yaml; its results are estimates.
 */
final readonly class TauxEstime
{
    /**
     * @param int $pointsDeBase rate in basis points: 350 = 3,5 %
     */
    public function __construct(
        public string $code,
        public string $libelle,
        public int $pointsDeBase,
        public CategorieBudget $assiette,
    ) {
        if ($pointsDeBase < 0 || $pointsDeBase >= 10_000) {
            throw new \InvalidArgumentException(\sprintf('The "%s" rate must be between 0 and 9999 basis points, %d given.', $code, $pointsDeBase));
        }
    }

    /**
     * The amount due on $assietteCentimes, rounded half up to the cent.
     */
    public function appliquer(int $assietteCentimes): int
    {
        return Arrondi::diviser($assietteCentimes * $this->pointsDeBase, 10_000);
    }

    /**
     * "3,5 %".
     */
    public function pourcentage(): string
    {
        return rtrim(rtrim(\sprintf('%d,%02d', intdiv($this->pointsDeBase, 100), $this->pointsDeBase % 100), '0'), ',').' %';
    }
}
