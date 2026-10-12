<?php

namespace App\Tests\Soiree;

use App\Entity\Soiree;
use App\Repository\SoireeRepository;
use App\Tests\Support\ActsAsMember;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Relies on AppFixtures: "Concert de printemps" of the Jardin, where Camille
 * is admin, Théo member, Inès reader; Hugo only belongs to the Atelier.
 */
final class SoireeWorkflowTest extends KernelTestCase
{
    use ActsAsMember;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testAdminTakesTheSoireeThroughEveryStatus(): void
    {
        $this->actAs('camille@example.com');
        $soiree = $this->soiree();
        self::assertSame('preparation', $soiree->getStatut());

        foreach (['confirmer' => 'confirmee', 'terminer' => 'terminee', 'faire_bilan' => 'bilan_fait'] as $transition => $statut) {
            self::assertSame([$transition], $this->enabledTransitions($soiree));
            $this->workflow()->apply($soiree, $transition);
            self::assertSame($statut, $soiree->getStatut());
        }

        self::assertSame([], $this->enabledTransitions($soiree));
    }

    public function testStatusesCannotBeSkipped(): void
    {
        $this->actAs('camille@example.com');
        $soiree = $this->soiree();

        self::assertFalse($this->workflow()->can($soiree, 'terminer'));
        self::assertFalse($this->workflow()->can($soiree, 'faire_bilan'));
    }

    public function testMemberOnlyTerminates(): void
    {
        $this->actAs('camille@example.com');
        $this->workflow()->apply($this->soiree(), 'confirmer');

        $this->actAs('theo@example.com');
        $soiree = $this->soiree();
        self::assertSame(['terminer'], $this->enabledTransitions($soiree));
        $this->workflow()->apply($soiree, 'terminer');

        self::assertFalse($this->workflow()->can($soiree, 'faire_bilan'));
    }

    public function testMemberCannotConfirm(): void
    {
        $this->actAs('theo@example.com');
        $soiree = $this->soiree();

        $blockers = $this->workflow()->buildTransitionBlockerList($soiree, 'confirmer');
        self::assertFalse($blockers->isEmpty());
        self::assertSame([], $this->enabledTransitions($soiree));
    }

    public function testReaderChangesNothing(): void
    {
        $this->actAs('camille@example.com');
        $this->workflow()->apply($this->soiree(), 'confirmer');

        $this->actAs('ines@example.com');
        self::assertSame([], $this->enabledTransitions($this->soiree()));
    }

    public function testMemberOfAnotherCollectiveIsDenied(): void
    {
        $this->actAs('camille@example.com');
        $soiree = $this->soiree();

        // Hugo only belongs to the Atelier; the soirée object is already loaded.
        $this->actAs('hugo@example.com');
        self::assertSame([], $this->enabledTransitions($soiree));

        // A new request starts with an empty identity map: the soirée is then out of reach.
        static::getContainer()->get('doctrine.orm.entity_manager')->clear();
        self::assertNull(static::getContainer()->get(SoireeRepository::class)->find((int) $soiree->getId()));
    }

    private function soiree(): Soiree
    {
        $soiree = static::getContainer()->get(SoireeRepository::class)->findOneBy(['nom' => 'Concert de printemps']);
        self::assertInstanceOf(Soiree::class, $soiree);

        return $soiree;
    }

    /**
     * @return list<string>
     */
    private function enabledTransitions(Soiree $soiree): array
    {
        return array_values(array_map(static fn ($transition): string => $transition->getName(), $this->workflow()->getEnabledTransitions($soiree)));
    }

    private function workflow(): WorkflowInterface
    {
        return static::getContainer()->get('state_machine.soiree');
    }
}
