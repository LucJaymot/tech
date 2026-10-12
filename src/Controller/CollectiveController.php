<?php

namespace App\Controller;

use App\Collective\ActiveCollective;
use App\Entity\User;
use App\Repository\InvitationRepository;
use App\Repository\MembershipRepository;
use App\Security\Voter\CollectiveVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class CollectiveController extends AbstractController
{
    public function __construct(
        private readonly ActiveCollective $activeCollective,
    ) {
    }

    #[Route('/collectif', name: 'app_collective', methods: ['GET'])]
    public function show(#[CurrentUser] User $user, MembershipRepository $memberships, InvitationRepository $invitations): Response
    {
        $collective = $this->activeCollective->get();
        $canManage = null !== $collective && $this->isGranted(CollectiveVoter::MANAGE_MEMBERS, $collective);

        return $this->render('collective/show.html.twig', [
            'collective' => $collective,
            'memberships' => $memberships->findForActiveCollective(),
            'choices' => $this->activeCollective->getChoices($user),
            'can_manage' => $canManage,
            'invitations' => $canManage ? $invitations->findPending() : [],
        ]);
    }

    #[Route('/collectif/actif', name: 'app_collective_switch', methods: ['POST'])]
    #[IsCsrfTokenValid('switch-collective')]
    public function switch(#[CurrentUser] User $user, Request $request): Response
    {
        if (!$this->activeCollective->switchTo($user, $request->request->getInt('collective'))) {
            throw $this->createAccessDeniedException('Not a member of this collective.');
        }

        $this->addFlash('success', \sprintf('Collectif actif : %s.', $this->activeCollective->get()?->getName()));

        return $this->redirectToRoute('app_collective');
    }
}
