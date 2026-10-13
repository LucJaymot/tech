<?php

namespace App\Obligations;

use App\Entity\Soiree;
use App\Entity\TacheAdmin;
use App\Event\SoireeParametresModifies;
use App\Repository\SoireeRepository;
use App\Repository\TacheAdminRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Keeps a soirée's TacheAdmin in line with the rules: adds the tasks of rules
 * that now apply, moves due dates, removes tasks whose rule no longer applies.
 * Done tasks are never touched. Running it again changes nothing.
 */
final class RetroplanningGenerator
{
    public function __construct(
        private readonly Obligations $obligations,
        private readonly TacheAdminRepository $taches,
        private readonly SoireeRepository $soirees,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $bus,
    ) {
    }

    /**
     * Persists the changes; the caller flushes.
     */
    public function generer(Soiree $soiree): void
    {
        $existantes = [];
        foreach ($this->taches->findPourSoiree($soiree) as $tache) {
            $existantes[$tache->getCode()] = $tache;
        }

        foreach ($this->obligations->regles() as $regle) {
            if (!$regle->sApplique($soiree)) {
                continue;
            }
            $tache = $existantes[$regle->code] ?? null;
            unset($existantes[$regle->code]);
            if (null === $tache) {
                $this->entityManager->persist(new TacheAdmin($soiree, $regle));
            } elseif (!$tache->estFaite()) {
                $tache->appliquer($regle);
            }
        }

        foreach ($existantes as $tache) {
            if (!$tache->estFaite()) {
                $this->entityManager->remove($tache);
            }
        }
    }

    /**
     * The event is dispatched after a flush, where flushing again is unsafe:
     * hand it over to Messenger instead.
     */
    #[AsEventListener]
    public function onSoireeParametresModifies(SoireeParametresModifies $event): void
    {
        $this->bus->dispatch($event);
    }

    #[AsMessageHandler]
    public function __invoke(SoireeParametresModifies $message): void
    {
        $soiree = $this->soirees->find($message->soireeId);
        if (null === $soiree) {
            return;
        }

        $this->generer($soiree);
        $this->entityManager->flush();
    }
}
