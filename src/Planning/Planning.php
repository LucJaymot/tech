<?php

namespace App\Planning;

use App\Entity\Affectation;
use App\Entity\Benevole;
use App\Entity\Creneau;
use App\Entity\Poste;
use App\Entity\Soiree;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Changes to a soirée's volunteer planning. Every change is validated before
 * it is saved; refusals carry a message for the user.
 */
final class Planning
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
    ) {
    }

    /**
     * @throws PlanningRefuse e.g. when the volunteer is already busy at that time
     */
    public function affecter(Creneau $creneau, Benevole $benevole): Affectation
    {
        $affectation = new Affectation($creneau, $benevole);
        $this->enregistrer($affectation, static fn () => $creneau->getAffectations()->removeElement($affectation));

        return $affectation;
    }

    public function retirer(Affectation $affectation): void
    {
        $affectation->getCreneau()->getAffectations()->removeElement($affectation);
        $this->entityManager->remove($affectation);
        $this->entityManager->flush();
    }

    /**
     * @throws PlanningRefuse
     */
    public function ajouterPoste(Soiree $soiree, string $intitule): Poste
    {
        $poste = new Poste($soiree, trim($intitule));
        $this->enregistrer($poste, static fn () => $soiree->getPostes()->removeElement($poste));

        return $poste;
    }

    /**
     * @param string $debut "HH:MM", see Horaires
     * @param string $fin   "HH:MM", see Horaires
     *
     * @throws PlanningRefuse
     */
    public function ajouterCreneau(Poste $poste, string $debut, string $fin, int $nombreRequis): Creneau
    {
        try {
            [$debutAt, $finAt] = Horaires::plage($poste->getSoiree(), $debut, $fin);
        } catch (\InvalidArgumentException $e) {
            throw new PlanningRefuse($e->getMessage(), previous: $e);
        }

        $creneau = new Creneau($poste, $debutAt, $finAt, $nombreRequis);
        $this->enregistrer($creneau, static fn () => $poste->getCreneaux()->removeElement($creneau));

        return $creneau;
    }

    /**
     * @throws PlanningRefuse
     */
    public function ajouterBenevole(Soiree $soiree, string $nom, ?string $email): Benevole
    {
        $benevole = (new Benevole($soiree->getCollective(), trim($nom)))->setEmail('' === trim((string) $email) ? null : trim((string) $email));
        $this->enregistrer($benevole, static fn () => null);

        return $benevole;
    }

    /**
     * @param callable(): mixed $annuler detaches the refused object from its parent
     */
    private function enregistrer(object $objet, callable $annuler): void
    {
        $violations = $this->validator->validate($objet);
        if (\count($violations) > 0) {
            $annuler();

            throw new PlanningRefuse((string) $violations->get(0)->getMessage());
        }

        $this->entityManager->persist($objet);
        $this->entityManager->flush();
    }
}
