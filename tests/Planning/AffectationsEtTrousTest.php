<?php

namespace App\Tests\Planning;

use App\Entity\Affectation;
use App\Entity\Benevole;
use App\Entity\Collective;
use App\Entity\Creneau;
use App\Entity\Disponibilite;
use App\Entity\Poste;
use App\Entity\Soiree;
use App\Planning\Planning;
use App\Planning\PlanningRefuse;
use App\Planning\Trou;
use App\Repository\BenevoleRepository;
use App\Repository\CreneauRepository;
use App\Repository\SoireeRepository;
use App\Repository\UserRepository;
use App\Tests\Support\ActsAsMember;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Relies on AppFixtures: on "Concert de printemps", Accueil 18:30–22:00 (2,
 * Léa), Buvette 19:00–22:00 (2, Théo) and 22:00–01:00 (2, nobody).
 */
final class AffectationsEtTrousTest extends KernelTestCase
{
    use ActsAsMember;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->actAs('camille@example.com');
    }

    public function testGapsComeFromRequiredVersusAssigned(): void
    {
        self::assertSame([
            'Accueil 18:30 2/1 → 1',
            'Buvette 19:00 2/1 → 1',
            'Buvette 22:00 2/0 → 2',
        ], $this->trous());
    }

    public function testAssigningFillsTheGap(): void
    {
        $this->planning()->affecter($this->creneau('Buvette', '22:00'), $this->benevole('Sam'));

        self::assertContains('Buvette 22:00 2/1 → 1', $this->trous());
    }

    public function testFullCreneauIsNoLongerAGap(): void
    {
        $accueil = $this->creneau('Accueil', '18:30');
        $this->planning()->affecter($accueil, $this->benevole('Sam'));

        self::assertNotContains('Accueil', array_map(static fn (string $t): string => strtok($t, ' ') ?: '', $this->trous()));
    }

    public function testVolunteerCannotBeOnTwoOverlappingCreneaux(): void
    {
        // Léa is at the Accueil 18:30–22:00.
        try {
            $this->planning()->affecter($this->creneau('Buvette', '19:00'), $this->benevole('Léa Fontaine'));
            self::fail('Overlapping créneaux must be refused.');
        } catch (PlanningRefuse $e) {
            self::assertStringContainsString('Léa Fontaine est déjà sur « Accueil »', $e->getMessage());
            self::assertStringContainsString('qui chevauche ce créneau', $e->getMessage());
        }

        self::assertSame(['Buvette 19:00 2/1 → 1'], array_values(array_filter($this->trous(), static fn (string $t): bool => str_starts_with($t, 'Buvette 19'))));
    }

    public function testBackToBackCreneauxAreAllowed(): void
    {
        // Accueil ends at 22:00, the night bar starts at 22:00.
        $this->planning()->affecter($this->creneau('Buvette', '22:00'), $this->benevole('Léa Fontaine'));

        self::assertContains('Buvette 22:00 2/1 → 1', $this->trous());
    }

    public function testOverlapIsCheckedAcrossSoirees(): void
    {
        $jardin = $this->soiree();
        $autre = new Soiree($jardin->getCollective(), 'Apéro voisin', $jardin->getDate(), new \DateTimeImmutable('18:00'), new \DateTimeImmutable('23:00'));
        $creneau = new Creneau(new Poste($autre, 'Bar'), $jardin->getDate()->setTime(21, 0), $jardin->getDate()->setTime(23, 0));
        static::getContainer()->get('doctrine.orm.entity_manager')->persist($autre);
        static::getContainer()->get('doctrine.orm.entity_manager')->flush();

        $this->expectException(PlanningRefuse::class);
        $this->planning()->affecter($creneau, $this->benevole('Léa Fontaine'));
    }

    public function testSameCreneauTwiceIsRefused(): void
    {
        $this->expectExceptionObject(new PlanningRefuse('Ce bénévole est déjà sur ce créneau.'));
        $this->planning()->affecter($this->creneau('Accueil', '18:30'), $this->benevole('Léa Fontaine'));
    }

    public function testVolunteerOfAnotherCollectiveIsRefused(): void
    {
        $etranger = new Benevole(new Collective('Ailleurs', 'ailleurs'), 'Étranger');

        $this->expectExceptionObject(new PlanningRefuse('Ce bénévole n\'est pas dans le collectif.'));
        $this->planning()->affecter($this->creneau('Buvette', '22:00'), $etranger);
    }

    public function testVolunteerAccountIsOptionalButUniquePerCollective(): void
    {
        $theo = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'theo@example.com']);
        self::assertNotNull($theo);
        self::assertNull($this->benevole('Sam')->getUser());
        self::assertSame($theo, $this->benevole('Théo Lambert')->getUser());

        $doublon = (new Benevole($this->soiree()->getCollective(), 'Autre Théo'))->setUser($theo);
        $violations = static::getContainer()->get(ValidatorInterface::class)->validate($doublon);

        self::assertCount(1, $violations);
        self::assertSame('Ce compte est déjà lié à un bénévole du collectif.', $violations->get(0)->getMessage());
    }

    public function testVolunteeringIsInvisibleFromAnotherCollective(): void
    {
        $this->actAs('hugo@example.com');
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $em->clear();

        foreach ([Benevole::class, Creneau::class, Poste::class, Affectation::class, Disponibilite::class] as $classe) {
            self::assertSame([], $em->getRepository($classe)->findAll(), $classe);
        }
    }

    /**
     * @return list<string>
     */
    private function trous(): array
    {
        return array_map(
            static fn (Trou $t): string => \sprintf('%s %s %d/%d → %d', $t->creneau->getPoste()->getIntitule(), $t->creneau->getDebut()->format('H:i'), $t->requis, $t->affectes, $t->manquants()),
            static::getContainer()->get(CreneauRepository::class)->findTrous($this->soiree()),
        );
    }

    private function creneau(string $poste, string $debut): Creneau
    {
        foreach (static::getContainer()->get(CreneauRepository::class)->findBy(['soiree' => $this->soiree()]) as $creneau) {
            if ($poste === $creneau->getPoste()->getIntitule() && $debut === $creneau->getDebut()->format('H:i')) {
                return $creneau;
            }
        }
        self::fail(\sprintf('No %s créneau at %s in the fixtures.', $poste, $debut));
    }

    private function benevole(string $nom): Benevole
    {
        $benevole = static::getContainer()->get(BenevoleRepository::class)->findOneBy(['nom' => $nom]);
        self::assertInstanceOf(Benevole::class, $benevole);

        return $benevole;
    }

    private function soiree(): Soiree
    {
        $soiree = static::getContainer()->get(SoireeRepository::class)->findOneBy(['nom' => 'Concert de printemps']);
        self::assertInstanceOf(Soiree::class, $soiree);

        return $soiree;
    }

    private function planning(): Planning
    {
        return static::getContainer()->get(Planning::class);
    }
}
