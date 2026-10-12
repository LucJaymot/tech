<?php

namespace App\Planning;

use App\Collective\ActiveCollective;
use App\Entity\Benevole;
use App\Entity\Creneau;
use App\Entity\Disponibilite;
use App\Entity\Soiree;
use App\Enum\SoireeStatut;
use App\Repository\AffectationRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The personal page of a volunteer for a soirée, reached from a signed link
 * without an account, hence outside the collective filter.
 */
final class EspaceBenevole
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ActiveCollective $activeCollective,
        private readonly AffectationRepository $affectations,
    ) {
    }

    public function vue(int $benevoleId, int $soireeId): ?VueEspaceBenevole
    {
        return $this->activeCollective->withoutFilter(function () use ($benevoleId, $soireeId): ?VueEspaceBenevole {
            [$benevole, $soiree] = $this->charger($benevoleId, $soireeId) ?? [null, null];
            if (null === $benevole || null === $soiree) {
                return null;
            }

            $affectes = $disponibles = [];
            $affectations = [];
            foreach ($this->affectations->findPourBenevoleEtSoiree($benevole, $soireeId) as $affectation) {
                $creneau = $affectation->getCreneau();
                $affectes[(int) $creneau->getId()] = true;
                $affectations[] = ['poste' => $creneau->getPoste()->getIntitule(), 'debut' => $creneau->getDebut(), 'fin' => $creneau->getFin()];
            }
            foreach ($this->disponibilites($benevole, $soiree) as $disponibilite) {
                $disponibles[(int) $disponibilite->getCreneau()->getId()] = true;
            }

            $creneauxParPoste = [];
            foreach ($this->creneaux($soiree) as $creneau) {
                $id = (int) $creneau->getId();
                $creneauxParPoste[$creneau->getPoste()->getIntitule()][] = [
                    'id' => $id,
                    'debut' => $creneau->getDebut(),
                    'fin' => $creneau->getFin(),
                    'disponible' => isset($disponibles[$id]),
                    'affecte' => isset($affectes[$id]),
                ];
            }

            $lieu = $soiree->getLieu();

            return new VueEspaceBenevole(
                benevole: $benevole->getNom(),
                soiree: $soiree->getNom(),
                date: $soiree->getDate(),
                ouverture: $soiree->getOuverture(),
                fermeture: $soiree->getFermeture(),
                lieu: $lieu?->getNom(),
                adresse: $lieu?->getAdresse(),
                acces: $lieu?->getAcces(),
                affectations: $affectations,
                creneauxParPoste: $creneauxParPoste,
                modifiable: $this->modifiable($soiree),
            );
        });
    }

    /**
     * Replaces the volunteer's availabilities for the soirée with $creneauIds.
     * Ids of other soirées are ignored.
     *
     * @param list<int> $creneauIds
     *
     * @return bool false when the link does not match or the soirée is over
     */
    public function enregistrerDisponibilites(int $benevoleId, int $soireeId, array $creneauIds): bool
    {
        return $this->activeCollective->withoutFilter(function () use ($benevoleId, $soireeId, $creneauIds): bool {
            [$benevole, $soiree] = $this->charger($benevoleId, $soireeId) ?? [null, null];
            if (null === $benevole || null === $soiree || !$this->modifiable($soiree)) {
                return false;
            }

            $voulus = array_flip($creneauIds);
            $existantes = [];
            foreach ($this->disponibilites($benevole, $soiree) as $disponibilite) {
                $id = (int) $disponibilite->getCreneau()->getId();
                if (isset($voulus[$id])) {
                    $existantes[$id] = true;
                } else {
                    $this->entityManager->remove($disponibilite);
                }
            }
            foreach ($this->creneaux($soiree) as $creneau) {
                $id = (int) $creneau->getId();
                if (isset($voulus[$id]) && !isset($existantes[$id])) {
                    $this->entityManager->persist(new Disponibilite($creneau, $benevole));
                }
            }
            $this->entityManager->flush();

            return true;
        });
    }

    /**
     * @return array{Benevole, Soiree}|null null unless the volunteer belongs to the soirée's collective
     */
    private function charger(int $benevoleId, int $soireeId): ?array
    {
        $soiree = $this->entityManager->createQueryBuilder()
            ->select('s', 'l')
            ->from(Soiree::class, 's')
            ->leftJoin('s.lieu', 'l')
            ->where('s.id = :id')
            ->setParameter('id', $soireeId)
            ->getQuery()
            ->getOneOrNullResult();
        $benevole = $this->entityManager->find(Benevole::class, $benevoleId);

        if (!$soiree instanceof Soiree || null === $benevole || $benevole->getCollective()->getId() !== $soiree->getCollective()->getId()) {
            return null;
        }

        return [$benevole, $soiree];
    }

    /**
     * @return list<Creneau>
     */
    private function creneaux(Soiree $soiree): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('c', 'p')
            ->from(Creneau::class, 'c')
            ->join('c.poste', 'p')
            ->where('c.soiree = :soiree')
            ->setParameter('soiree', $soiree)
            ->orderBy('p.id')
            ->addOrderBy('c.debut')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Disponibilite>
     */
    private function disponibilites(Benevole $benevole, Soiree $soiree): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('d', 'c')
            ->from(Disponibilite::class, 'd')
            ->join('d.creneau', 'c')
            ->where('d.benevole = :benevole')
            ->andWhere('c.soiree = :soiree')
            ->setParameter('benevole', $benevole)
            ->setParameter('soiree', $soiree)
            ->getQuery()
            ->getResult();
    }

    private function modifiable(Soiree $soiree): bool
    {
        return \in_array($soiree->getStatutEnum(), [SoireeStatut::Preparation, SoireeStatut::Confirmee], true);
    }
}
