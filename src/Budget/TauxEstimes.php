<?php

namespace App\Budget;

use App\Enum\CategorieBudget;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The estimated rates from the "app.budget.taux_estimes" parameter.
 */
final class TauxEstimes
{
    /** @var list<TauxEstime> */
    private readonly array $taux;

    /**
     * @param list<array{code: string, libelle: string, points_de_base: int, assiette: string}> $configuration
     */
    public function __construct(
        #[Autowire(param: 'app.budget.taux_estimes')]
        array $configuration,
    ) {
        $this->taux = array_map(
            static fn (array $taux): TauxEstime => new TauxEstime($taux['code'], $taux['libelle'], $taux['points_de_base'], CategorieBudget::from($taux['assiette'])),
            $configuration,
        );
    }

    /**
     * @return list<TauxEstime>
     */
    public function tous(): array
    {
        return $this->taux;
    }
}
