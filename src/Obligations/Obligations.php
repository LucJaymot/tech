<?php

namespace App\Obligations;

use App\Entity\Soiree;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The rules, yearly limits and checks of the night from config/packages/obligations.yaml.
 */
final class Obligations
{
    /** @var list<Regle> */
    private readonly array $regles;

    /** @var list<SeuilAnnuel> */
    private readonly array $seuils;

    /** @var list<CheckSoiree> */
    private readonly array $checks;

    /**
     * @param list<array{code: string, libelle: string, conditions: array<string, bool|int>, decalage_jours: int, source: string, verifiee_le: string}> $regles
     * @param list<array{code: string, libelle: string, critere: string, limite: int, source: string, verifiee_le: string}>                             $seuilsAnnuels
     * @param list<array{code: string, libelle: string, conditions: array<string, bool|int>, source: string, verifiee_le: string}>                      $checks
     */
    public function __construct(
        #[Autowire(param: 'app.obligations.regles')]
        array $regles,
        #[Autowire(param: 'app.obligations.seuils_annuels')]
        array $seuilsAnnuels,
        #[Autowire(param: 'app.obligations.checks')]
        array $checks,
    ) {
        $this->regles = array_map(
            static fn (array $r): Regle => new Regle($r['code'], $r['libelle'], new Conditions($r['conditions'], $r['code']), $r['decalage_jours'], $r['source'], self::date($r['verifiee_le'])),
            $regles,
        );
        $this->seuils = array_map(
            static fn (array $s): SeuilAnnuel => new SeuilAnnuel($s['code'], $s['libelle'], $s['critere'], $s['limite'], $s['source'], self::date($s['verifiee_le'])),
            $seuilsAnnuels,
        );
        $this->checks = array_map(
            static fn (array $c): CheckSoiree => new CheckSoiree($c['code'], $c['libelle'], new Conditions($c['conditions'], $c['code']), $c['source'], self::date($c['verifiee_le'])),
            $checks,
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

    /**
     * @return list<CheckSoiree>
     */
    public function checks(): array
    {
        return $this->checks;
    }

    /**
     * @return list<CheckSoiree> those that apply to $soiree
     */
    public function checksPour(Soiree $soiree): array
    {
        return array_values(array_filter($this->checks, static fn (CheckSoiree $check): bool => $check->sApplique($soiree)));
    }

    private static function date(string $date): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromFormat('!Y-m-d', $date) ?: throw new \InvalidArgumentException(\sprintf('"%s" is not a Y-m-d date.', $date));
    }
}
