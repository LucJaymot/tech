<?php

namespace App\EventListener;

use App\Entity\Soiree;
use App\Entity\TacheAdmin;
use App\Repository\TacheAdminRepository;
use Symfony\Component\Workflow\Attribute\AsGuardListener;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\TransitionBlocker;

/**
 * A soirée's bilan cannot be marked done while an administrative task due
 * after it (the Guso declaration, for one) is still open.
 */
#[AsGuardListener(workflow: 'soiree', transition: 'faire_bilan')]
final class BilanBloqueListener
{
    public const string CODE = 'taches_apres_soiree_ouvertes';

    public function __construct(
        private readonly TacheAdminRepository $taches,
    ) {
    }

    /**
     * @param GuardEvent<object> $event
     */
    public function __invoke(GuardEvent $event): void
    {
        $soiree = $event->getSubject();
        if (!$soiree instanceof Soiree) {
            return;
        }

        $ouvertes = $this->taches->findApresSoireeOuvertes($soiree);
        if ([] !== $ouvertes) {
            $event->addTransitionBlocker(new TransitionBlocker(
                \sprintf('Démarche%s encore ouverte%s : %s.', \count($ouvertes) > 1 ? 's' : '', \count($ouvertes) > 1 ? 's' : '', implode(', ', array_map(static fn (TacheAdmin $t): string => $t->getLibelle(), $ouvertes))),
                self::CODE,
            ));
        }
    }
}
