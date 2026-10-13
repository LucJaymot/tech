<?php

namespace App\EventListener;

use App\Entity\Soiree;
use App\Enum\SoireeParametre;
use App\Event\SoireeParametresModifies;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Publishes SoireeParametresModifies when a soirée is created, and for every
 * saved change of one of its SoireeParametre, whatever code made it. Events
 * are dispatched after the flush, so listeners see the saved data.
 */
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
#[AsDoctrineListener(event: Events::postFlush)]
final class SoireeParametresListener implements ResetInterface
{
    /** @var array<int, SoireeParametresModifies> by soirée id */
    private array $pending = [];

    public function __construct(
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    /**
     * A new soirée: every parameter is new.
     */
    public function postPersist(PostPersistEventArgs $args): void
    {
        $soiree = $args->getObject();
        if ($soiree instanceof Soiree && null !== $soiree->getId() && null !== $soiree->getCollective()->getId()) {
            $this->pending[$soiree->getId()] = new SoireeParametresModifies($soiree->getId(), $soiree->getCollective()->getId(), SoireeParametre::cases());
        }
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $soiree = $args->getObject();
        if (!$soiree instanceof Soiree || null === $soiree->getId() || null === $soiree->getCollective()->getId()) {
            return;
        }

        $changed = $args->getObjectManager()->getUnitOfWork()->getEntityChangeSet($soiree);
        $parametres = array_values(array_filter(
            SoireeParametre::cases(),
            static fn (SoireeParametre $parametre): bool => \array_key_exists($parametre->value, $changed),
        ));

        if ([] !== $parametres) {
            $this->pending[$soiree->getId()] = new SoireeParametresModifies($soiree->getId(), $soiree->getCollective()->getId(), $parametres);
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        [$events, $this->pending] = [$this->pending, []];

        foreach ($events as $event) {
            $this->dispatcher->dispatch($event);
        }
    }

    public function reset(): void
    {
        $this->pending = [];
    }
}
