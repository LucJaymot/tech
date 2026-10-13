<?php

namespace App\Tests\Bilan;

use App\Bilan\BilanAnnuel;
use App\Budget\BudgetCalculator;
use App\Budget\TauxEstimes;
use App\Entity\LigneBudget;
use App\Entity\Soiree;
use App\Enum\CategorieBudget;
use App\Enum\LigneBudgetType;
use App\Repository\SoireeRepository;
use App\Tests\Support\ActsAsMember;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Relies on AppFixtures: the budget of "Concert de printemps" (Jardin).
 */
final class BilanAnnuelTest extends KernelTestCase
{
    use ActsAsMember;

    public function testEachSoireeMatchesItsBudgetComputedLineByLine(): void
    {
        self::bootKernel();
        $this->actAs('camille@example.com');
        $concert = $this->soiree();
        $annee = (int) $concert->getDate()->format('Y');

        // A second soirée mixing known and unknown actual amounts in one category, and a third without lines.
        $bal = new Soiree($concert->getCollective(), 'Bal', $concert->getDate(), new \DateTimeImmutable('20:00'), new \DateTimeImmutable('23:00'));
        (new LigneBudget($bal, LigneBudgetType::Recette, CategorieBudget::Billetterie, 'Prévente', 30000))->setMontantReel(27550);
        new LigneBudget($bal, LigneBudgetType::Recette, CategorieBudget::Billetterie, 'Sur place', 20000);
        (new LigneBudget($bal, LigneBudgetType::Depense, CategorieBudget::Technique, 'Sono', 12000))->setMontantReel(13001);
        $vide = new Soiree($concert->getCollective(), 'Répétition', $concert->getDate(), new \DateTimeImmutable('18:00'), new \DateTimeImmutable('19:00'));
        $this->entityManager()->persist($bal);
        $this->entityManager()->persist($vide);
        $this->entityManager()->flush();

        $bilan = static::getContainer()->get(BilanAnnuel::class)->pour($concert->getCollective(), $annee);

        $calculator = new BudgetCalculator();
        $taux = static::getContainer()->get(TauxEstimes::class)->tous();
        $parNom = array_column($bilan['soirees'], null, 'nom');
        self::assertSame(['Concert de printemps', 'Bal', 'Répétition'], array_keys($parNom));
        foreach ([$concert, $bal, $vide] as $soiree) {
            $attendu = $calculator->calculer(array_values(array_map(static fn (LigneBudget $l) => $l->montants(), $soiree->getLignesBudget()->toArray())), $taux);
            $obtenu = $parNom[$soiree->getNom()]['totaux'];
            foreach (['depensesPrevues', 'depensesReelles', 'recettesPrevues', 'recettesReelles', 'ecartDepenses', 'ecartRecettes'] as $champ) {
                self::assertSame($attendu->{$champ}, $obtenu->{$champ}, $soiree->getNom().' '.$champ);
            }
            self::assertSame($attendu->lignesSansReel, $parNom[$soiree->getNom()]['sans_reel'], $soiree->getNom());
        }
        self::assertSame(1, $parNom['Bal']['sans_reel']);
        self::assertSame(
            array_sum(array_map(static fn (array $s): int => $s['totaux']->soldeReel(), $bilan['soirees'])),
            $bilan['total']['solde_reel'],
        );
        self::assertSame([], static::getContainer()->get(BilanAnnuel::class)->pour($concert->getCollective(), $annee + 1)['soirees']);
    }

    private function soiree(): Soiree
    {
        $soiree = static::getContainer()->get(SoireeRepository::class)->findOneBy(['nom' => 'Concert de printemps']);
        self::assertInstanceOf(Soiree::class, $soiree);

        return $soiree;
    }

    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
