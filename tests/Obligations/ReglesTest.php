<?php

namespace App\Tests\Obligations;

use App\Entity\Collective;
use App\Entity\Lieu;
use App\Entity\Soiree;
use App\Obligations\CheckSoiree;
use App\Obligations\Compteur;
use App\Obligations\Conditions;
use App\Obligations\Obligations;
use App\Obligations\Regle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * One test per rule of config/packages/obligations.yaml, on the file itself.
 */
final class ReglesTest extends TestCase
{
    private const string DATE = '2026-06-20';

    public function testEveryRuleAndLimitHasItsTest(): void
    {
        $obligations = self::obligations();

        self::assertSame(
            ['sacem', 'buvette_temporaire', 'rassemblement_festif', 'manifestation_plus_1500', 'spectacle_occasionnel', 'guso_dus'],
            array_map(static fn (Regle $r): string => $r->code, $obligations->regles()),
        );
        self::assertSame(['representations', 'buvettes', 'manifestations_soutien'], array_map(static fn ($s): string => $s->code, $obligations->seuils()));
        self::assertSame(['releve_sonore', 'protections_auditives'], array_map(static fn (CheckSoiree $c): string => $c->code, $obligations->checks()));
        foreach ([...$obligations->regles(), ...$obligations->seuils(), ...$obligations->checks()] as $regle) {
            self::assertStringStartsWith('https://', $regle->source);
        }
    }

    public function testSacemForEverySoireeAtJMinus15(): void
    {
        self::assertSame('2026-06-05', self::echeance('sacem', self::soiree()));
    }

    /**
     * @return iterable<string, array{Soiree, ?string}>
     */
    public static function buvettes(): iterable
    {
        yield 'with a bar, J-15' => [self::soiree(buvette: true), '2026-06-05'];
        yield 'without' => [self::soiree(buvette: false), null];
    }

    #[DataProvider('buvettes')]
    public function testBuvetteTemporaire(Soiree $soiree, ?string $echeance): void
    {
        self::assertSame($echeance, self::echeance('buvette_temporaire', $soiree));
    }

    /**
     * @return iterable<string, array{Soiree, ?string}>
     */
    public static function rassemblements(): iterable
    {
        yield '500 people, venue not fitted out' => [self::soiree(jauge: 500, amenage: false), null];
        yield '501 people, venue not fitted out, J-30' => [self::soiree(jauge: 501, amenage: false), '2026-05-21'];
        yield '501 people, fitted-out venue' => [self::soiree(jauge: 501, amenage: true), null];
        yield '501 people, unknown venue' => [self::soiree(jauge: 501), null];
        yield 'unknown jauge, venue not fitted out' => [self::soiree(amenage: false), null];
    }

    #[DataProvider('rassemblements')]
    public function testRassemblementFestif(Soiree $soiree, ?string $echeance): void
    {
        self::assertSame($echeance, self::echeance('rassemblement_festif', $soiree));
    }

    /**
     * @return iterable<string, array{Soiree, ?string}>
     */
    public static function grandesManifestations(): iterable
    {
        yield '1 500 people' => [self::soiree(jauge: 1500, amenage: true), null];
        yield '1 501 people, J-30' => [self::soiree(jauge: 1501, amenage: true), '2026-05-21'];
        yield 'unknown jauge' => [self::soiree(amenage: true), null];
    }

    #[DataProvider('grandesManifestations')]
    public function testManifestationPlus1500(Soiree $soiree, ?string $echeance): void
    {
        self::assertSame($echeance, self::echeance('manifestation_plus_1500', $soiree));
    }

    /**
     * @return iterable<string, array{string, bool, ?string}>
     */
    public static function artistesPayes(): iterable
    {
        yield 'spectacle occasionnel, paid artists, J-30' => ['spectacle_occasionnel', true, '2026-05-21'];
        yield 'spectacle occasionnel, unpaid' => ['spectacle_occasionnel', false, null];
        yield 'Guso, paid artists, J+15' => ['guso_dus', true, '2026-07-05'];
        yield 'Guso, unpaid' => ['guso_dus', false, null];
    }

    #[DataProvider('artistesPayes')]
    public function testRulesOfPaidArtists(string $code, bool $payes, ?string $echeance): void
    {
        self::assertSame($echeance, self::echeance($code, self::soiree(artistesPayes: $payes)));
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function compteurs(): iterable
    {
        yield '4 of 6 shows' => ['representations', 4, 'ok'];
        yield '5 of 6 shows: one left' => ['representations', 5, 'attention'];
        yield '6 of 6 shows: reached' => ['representations', 6, 'attention'];
        yield '7 of 6 shows: over' => ['representations', 7, 'bloquant'];
        yield '3 of 5 bars' => ['buvettes', 3, 'ok'];
        yield '4 of 5 bars: one left' => ['buvettes', 4, 'attention'];
        yield '6 of 5 bars: over' => ['buvettes', 6, 'bloquant'];
        yield '5 of 6 fundraisers: one left' => ['manifestations_soutien', 5, 'attention'];
        yield '7 of 6 fundraisers: over' => ['manifestations_soutien', 7, 'bloquant'];
    }

    #[DataProvider('compteurs')]
    public function testYearlyLimitAlertsBeforeItIsReached(string $code, int $nombre, string $statut): void
    {
        $seuil = array_values(array_filter(self::obligations()->seuils(), static fn ($s): bool => $s->code === $code))[0];

        self::assertSame($statut, (new Compteur($seuil, 2026, $nombre))->statut());
    }

    /**
     * @return iterable<string, array{?int, list<string>}>
     */
    public static function checksDeLaNuit(): iterable
    {
        yield '300 people' => [300, ['protections_auditives']];
        yield '301 people: sound level recording' => [301, ['releve_sonore', 'protections_auditives']];
        yield 'unknown jauge' => [null, ['protections_auditives']];
    }

    /**
     * @param list<string> $codes
     */
    #[DataProvider('checksDeLaNuit')]
    public function testChecksOfTheNight(?int $jauge, array $codes): void
    {
        self::assertSame($codes, array_map(static fn (CheckSoiree $c): string => $c->code, self::obligations()->checksPour(self::soiree(jauge: $jauge))));
    }

    public function testUnknownConditionIsRejected(): void
    {
        $this->expectExceptionMessage('Unknown condition(s) "jauge_sup"');

        new Conditions(['jauge_sup' => 500], 'x');
    }

    private static function echeance(string $code, Soiree $soiree): ?string
    {
        $regle = array_values(array_filter(self::obligations()->regles(), static fn (Regle $r): bool => $r->code === $code))[0];

        return $regle->sApplique($soiree) ? $regle->echeance($soiree)->format('Y-m-d') : null;
    }

    private static function soiree(?int $jauge = null, ?bool $amenage = null, bool $buvette = false, bool $artistesPayes = false): Soiree
    {
        $collective = new Collective('Test', 'test');
        $soiree = (new Soiree($collective, 'Test', new \DateTimeImmutable(self::DATE), new \DateTimeImmutable('20:00'), new \DateTimeImmutable('23:00')))
            ->setJauge($jauge)
            ->setBuvette($buvette)
            ->setArtistesPayes($artistesPayes);
        if (null !== $amenage) {
            $soiree->setLieu((new Lieu($collective, 'Lieu', 'Adresse', 5000))->setAmenagePourPublic($amenage));
        }

        return $soiree;
    }

    private static function obligations(): Obligations
    {
        $parametres = Yaml::parseFile(__DIR__.'/../../config/packages/obligations.yaml')['parameters'];

        return new Obligations($parametres['app.obligations.regles'], $parametres['app.obligations.seuils_annuels'], $parametres['app.obligations.checks']);
    }
}
