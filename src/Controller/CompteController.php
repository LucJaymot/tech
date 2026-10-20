<?php

namespace App\Controller;

use App\Collective\Membres;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The "Mon compte" card of /collectif: display name and account deletion.
 */
#[IsGranted('ROLE_USER')]
final class CompteController extends AbstractController
{
    #[Route('/compte/nom', name: 'app_compte_nom', methods: ['POST'])]
    #[IsCsrfTokenValid('compte')]
    public function nom(#[CurrentUser] User $user, Request $request, ValidatorInterface $validator, EntityManagerInterface $entityManager): Response
    {
        $nom = $user->getDisplayName();
        $user->setDisplayName(trim($request->request->getString('nom')));
        $violations = $validator->validateProperty($user, 'displayName');
        if (\count($violations) > 0) {
            $user->setDisplayName($nom);
            $this->addFlash('error', (string) $violations->get(0)->getMessage());
        } else {
            $entityManager->flush();
            $this->addFlash('success', 'Nom enregistré.');
        }

        return $this->redirectToRoute('app_collective', ['_fragment' => 'compte']);
    }

    /**
     * Same deletion as App\Rgpd\ComptesInactifs: the database cascades or nulls what points to the user.
     */
    #[Route('/compte/supprimer', name: 'app_compte_supprimer', methods: ['POST'])]
    #[IsCsrfTokenValid('supprimer-compte')]
    public function supprimer(#[CurrentUser] User $user, Request $request, Membres $membres, EntityManagerInterface $entityManager, Security $security): Response
    {
        if (!$request->request->getBoolean('confirmer')) {
            $this->addFlash('error', 'Cochez la case pour confirmer la suppression.');

            return $this->redirectToRoute('app_collective', ['_fragment' => 'compte']);
        }

        // shortcut: no lock, two last admins deleting at the same second could both pass; lock like Membres if it ever happens.
        if (null !== $collective = $membres->dernierAdminDe($user)) {
            $this->addFlash('error', \sprintf('Nommez un autre administrateur de %s avant de supprimer votre compte.', $collective->getName()));

            return $this->redirectToRoute('app_collective', ['_fragment' => 'compte']);
        }

        $security->logout(false);
        $entityManager->remove($user);
        $entityManager->flush();
        $this->addFlash('success', 'Votre compte a été supprimé.');

        return $this->redirectToRoute('app_login');
    }
}
