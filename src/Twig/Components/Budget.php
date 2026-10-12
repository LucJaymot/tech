<?php

namespace App\Twig\Components;

use App\Budget\BudgetCalculator;
use App\Budget\BudgetTotaux;
use App\Budget\Centimes;
use App\Budget\Justificatifs;
use App\Budget\TauxEstimes;
use App\Entity\LigneBudget;
use App\Entity\Soiree;
use App\Enum\CategorieBudget;
use App\Enum\LigneBudgetType;
use App\Security\Voter\CollectiveVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PostHydrate;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The budget of a soirée. Each amount is saved as soon as it changes, then
 * the totals are recomputed by BudgetCalculator.
 * Seeing it requires VIEW_BUDGET; changing it requires EDIT on the collective.
 */
#[AsLiveComponent]
final class Budget
{
    use DefaultActionTrait;

    #[LiveProp]
    public Soiree $soiree;

    /** @var array<int, array{prevu: string, reel: string}> amounts as typed, by line id */
    #[LiveProp(writable: true)]
    public array $montants = [];

    /** Average entry price as typed, to express the break-even point in entries. */
    #[LiveProp(writable: true)]
    public string $prixEntree = '';

    #[LiveProp(writable: true)]
    public string $nouveauType = 'depense';

    #[LiveProp(writable: true)]
    public string $nouvelleCategorie = 'divers';

    #[LiveProp(writable: true)]
    public string $nouveauLibelle = '';

    #[LiveProp(writable: true)]
    public string $nouveauPrevu = '';

    /** @var array<string, string> error messages by field, e.g. "12.reel" or "nouveau" */
    #[LiveProp]
    public array $erreurs = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly ValidatorInterface $validator,
        private readonly BudgetCalculator $calculator,
        private readonly TauxEstimes $taux,
        private readonly Justificatifs $justificatifs,
    ) {
    }

    public function mount(Soiree $soiree): void
    {
        $this->soiree = $soiree;
        $this->assertPeutVoir();
        $this->recharger();
    }

    /**
     * Runs on every re-render: checks access again, then saves the amounts
     * that differ from the database.
     */
    #[PostHydrate]
    public function enregistrerSaisies(): void
    {
        $this->assertPeutVoir();
        if (!$this->peutModifier()) {
            $this->recharger();

            return;
        }

        $modifie = false;
        foreach ($this->lignesModifiables() as $ligne) {
            $id = (int) $ligne->getId();
            if (!isset($this->montants[$id])) {
                continue;
            }

            foreach (['prevu', 'reel'] as $champ) {
                $saisie = trim($this->montants[$id][$champ]);
                try {
                    $valeur = ('reel' === $champ && '' === $saisie) ? null : Centimes::depuisSaisie($saisie);
                } catch (\InvalidArgumentException $e) {
                    $this->erreurs["$id.$champ"] = $e->getMessage();
                    continue;
                }
                unset($this->erreurs["$id.$champ"]);

                if ('prevu' === $champ && $valeur !== $ligne->getMontantPrevu()) {
                    $ligne->setMontantPrevu((int) $valeur);
                    $modifie = true;
                } elseif ('reel' === $champ && $valeur !== $ligne->getMontantReel()) {
                    $ligne->setMontantReel($valeur);
                    $modifie = true;
                }
                $this->montants[$id][$champ] = null === $valeur ? '' : Centimes::versSaisie($valeur);
            }
        }

        if ($modifie) {
            $this->entityManager->flush();
        }
    }

    #[LiveAction]
    public function ajouter(): void
    {
        $this->assertPeutModifier();
        unset($this->erreurs['nouveau']);

        try {
            $type = LigneBudgetType::from($this->nouveauType);
            $categorie = CategorieBudget::from($this->nouvelleCategorie);
            $ligne = new LigneBudget($this->soiree, $type, $categorie, trim($this->nouveauLibelle), Centimes::depuisSaisie($this->nouveauPrevu));
        } catch (\ValueError|\InvalidArgumentException $e) {
            $this->erreurs['nouveau'] = $e instanceof \ValueError ? 'Type ou catégorie inconnu.' : $e->getMessage();

            return;
        }

        $violations = $this->validator->validate($ligne);
        if (\count($violations) > 0) {
            $this->soiree->getLignesBudget()->removeElement($ligne);
            $this->erreurs['nouveau'] = (string) $violations->get(0)->getMessage();

            return;
        }

        $this->entityManager->persist($ligne);
        $this->entityManager->flush();
        $this->nouveauLibelle = $this->nouveauPrevu = '';
        $this->recharger();
    }

    #[LiveAction]
    public function supprimer(#[LiveArg] int $ligne): void
    {
        $this->assertPeutModifier();

        foreach ($this->lignesModifiables() as $candidate) {
            if ($candidate->getId() === $ligne) {
                foreach ($candidate->getJustificatifs()->toArray() as $justificatif) {
                    $this->justificatifs->supprimer($justificatif);
                }
                $this->soiree->getLignesBudget()->removeElement($candidate);
                $this->entityManager->remove($candidate);
                $this->entityManager->flush();
                $this->recharger();

                return;
            }
        }
    }

    /**
     * Expenses first, then income; by category.
     *
     * @return list<LigneBudget>
     */
    public function getLignes(): array
    {
        $lignes = array_values($this->soiree->getLignesBudget()->toArray());
        usort($lignes, static fn (LigneBudget $a, LigneBudget $b): int => [$a->getType()->value, $a->getCategorie()->value, $a->getId()] <=> [$b->getType()->value, $b->getCategorie()->value, $b->getId()]);

        return $lignes;
    }

    public function getTotaux(): BudgetTotaux
    {
        try {
            $prix = '' === trim($this->prixEntree) ? null : Centimes::depuisSaisie($this->prixEntree);
        } catch (\InvalidArgumentException) {
            $prix = null;
        }

        return $this->calculator->calculer(
            array_map(static fn (LigneBudget $l) => $l->montants(), $this->getLignes()),
            $this->taux->tous(),
            $prix > 0 ? $prix : null,
        );
    }

    /**
     * @return list<CategorieBudget>
     */
    public function getCategories(): array
    {
        return CategorieBudget::pour(LigneBudgetType::tryFrom($this->nouveauType) ?? LigneBudgetType::Depense);
    }

    public function peutModifier(): bool
    {
        return $this->authorizationChecker->isGranted(CollectiveVoter::EDIT, $this->soiree->getCollective());
    }

    /**
     * @return list<LigneBudget>
     */
    private function lignesModifiables(): array
    {
        return array_values(array_filter($this->getLignes(), static fn (LigneBudget $l): bool => !$l->estGeneree()));
    }

    private function recharger(): void
    {
        $this->montants = [];
        foreach ($this->lignesModifiables() as $ligne) {
            $this->montants[(int) $ligne->getId()] = [
                'prevu' => Centimes::versSaisie($ligne->getMontantPrevu()),
                'reel' => null === $ligne->getMontantReel() ? '' : Centimes::versSaisie($ligne->getMontantReel()),
            ];
        }
    }

    private function assertPeutVoir(): void
    {
        if (!$this->authorizationChecker->isGranted(CollectiveVoter::VIEW_BUDGET, $this->soiree->getCollective())) {
            throw new AccessDeniedException();
        }
    }

    private function assertPeutModifier(): void
    {
        if (!$this->peutModifier()) {
            throw new AccessDeniedException();
        }
    }
}
