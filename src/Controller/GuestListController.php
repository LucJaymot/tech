<?php

namespace App\Controller;

use App\Entity\GuestList;
use App\Entity\Invite;
use App\Entity\Soiree;
use App\Entity\User;
use App\GuestList\GuestListRefusee;
use App\GuestList\GuestLists;
use App\Repository\GuestListRepository;
use App\Security\Voter\CollectiveVoter;
use App\Security\Voter\SoireeVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Members fill their own guest list; admins see every list and set quotas.
 */
#[IsGranted('ROLE_USER')]
final class GuestListController extends AbstractController
{
    public function __construct(
        private readonly GuestLists $guestLists,
    ) {
    }

    #[Route('/soirees/{id}/guest-lists', name: 'app_guest_lists', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Soiree $soiree, #[CurrentUser] User $user, GuestListRepository $repository): Response
    {
        $this->denyAccessUnlessGranted(SoireeVoter::EDIT, $soiree);
        $admin = $this->isGranted(CollectiveVoter::MANAGE_MEMBERS, $soiree->getCollective());

        return $this->render('guest_list/show.html.twig', [
            'soiree' => $soiree,
            'ma_liste' => $this->guestLists->pourMembre($soiree, $user),
            'admin' => $admin,
            'listes' => $admin ? $repository->findPourSoiree($soiree) : [],
        ]);
    }

    #[Route('/soirees/{id}/guest-lists/invites', name: 'app_guest_list_ajouter', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid('guest-list')]
    public function ajouter(Soiree $soiree, Request $request, #[CurrentUser] User $user): Response
    {
        $this->denyAccessUnlessGranted(SoireeVoter::EDIT, $soiree);

        try {
            $invite = $this->guestLists->ajouter($this->guestLists->pourMembre($soiree, $user), $request->request->getString('nom'), $request->request->getInt('accompagnants'));
            $this->addFlash('success', \sprintf('%s ajouté·e à votre liste.', $invite->getNom()));
        } catch (GuestListRefusee $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_guest_lists', ['id' => $soiree->getId()]);
    }

    #[Route('/guest-lists/invites/{id}/retirer', name: 'app_guest_list_retirer', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid('guest-list')]
    public function retirer(Invite $invite, #[CurrentUser] User $user): Response
    {
        $liste = $invite->getGuestList();
        $this->denyAccessUnlessGranted(SoireeVoter::EDIT, $liste->getSoiree());
        if ($liste->getMembre() !== $user && !$this->isGranted(CollectiveVoter::MANAGE_MEMBERS, $liste->getCollective())) {
            throw $this->createAccessDeniedException('Not your guest list.');
        }

        try {
            $this->guestLists->retirer($invite);
            $this->addFlash('success', \sprintf('%s retiré·e.', $invite->getNom()));
        } catch (GuestListRefusee $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_guest_lists', ['id' => $liste->getSoiree()->getId()]);
    }

    #[Route('/guest-lists/{id}/quota', name: 'app_guest_list_quota', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid('guest-list')]
    public function quota(GuestList $liste, Request $request): Response
    {
        $this->denyAccessUnlessGranted(CollectiveVoter::MANAGE_MEMBERS, $liste->getCollective());

        try {
            $this->guestLists->changerQuota($liste, $request->request->getInt('quota'));
            $this->addFlash('success', \sprintf('Quota de %s : %d.', $liste->getProprietaire(), $liste->getQuota()));
        } catch (GuestListRefusee $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_guest_lists', ['id' => $liste->getSoiree()->getId()]);
    }
}
