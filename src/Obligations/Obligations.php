<?php

namespace App\Obligations;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The rules and yearly limits from config/packages/obligations.yaml.
 */
final class Obligations
{
    /** @var list<Regle> */
    private readonly array $regles;

    /** @var list<SeuilAnnuel> */
    private readonly array $seuils;

    /**
     * @param list<array{code: string, libelle: string, conditions: array<string, bool|int>, decalage_jours: int, source: string, verifiee_le: string}> $regles
     * @param list<array{code: string, libelle: string, critere: string, limite: int, source: string, verifiee_le: string}>                             $seuilsAnnuels
     */
    public function __construct(
        #[Autowire(param: 'app.obligations.regles')]
        array $regles,
        #[Autowire(param: 'app.obligations.seuils_annuels')]
        array $seuilsAnnuels,
    ) {
        $this->regles = array_map(
            static fn (array $r): Regle => new Regle($r['code'], $r['libelle'], $r['conditions'], $r['decalage_jours'], $r['source'], self::date($r['verifiee_le'])),
            $regles,
        );
        $this->seuils = array_map(
            static fn (array $s): SeuilAnnuel => new SeuilAnnuel($s['code'], $s['libelle'], $s['critere'], $s['limite'], $s['source'], self::date($s['verifiee_le'])),
            $seuilsAnnuels,
        );
    }

    /**
     * @return list<Regle>
     */
    public function regles(): array
    {
        return $this->regles;
    }

    /**
     * @return list<SeuilAnnuel>
     */
    public function seuils(): array
    {
        return $this->seuils;
    }

    private static function date(string $date): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromFormat('!Y-m-d', $date) ?: throw new \InvalidArgumentException(\sprintf('"%s" is not a Y-m-d date.', $date));
    }
}
