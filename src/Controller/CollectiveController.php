<?php

namespace App\Controller;

use App\Collective\ActiveCollective;
use App\Collective\Membres;
use App\Entity\Membership;
use App\Entity\User;
use App\Enum\MembershipRole;
use App\Enum\StyleTitres;
use App\Repository\InvitationRepository;
use App\Repository\MembershipRepository;
use App\Security\Voter\CollectiveVoter;
use App\Storage\FichierRefuse;
use App\Visuel\IdentiteVisuelle;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[IsGranted('ROLE_USER')]
final class CollectiveController extends AbstractController
{
    public function __construct(
        private readonly ActiveCollective $activeCollective,
    ) {
    }

    #[Route('/collectif', name: 'app_collective', methods: ['GET'])]
    /**
     * Members and settings of the collective; its soirées are on /soirees.
     */
    public function show(MembershipRepository $memberships, InvitationRepository $invitations, #[Autowire('%env(VAPID_PUBLIC_KEY)%')] string $clePush): Response
    {
        $collective = $this->activeCollective->get();
        $canManage = null !== $collective && $this->isGranted(CollectiveVoter::MANAGE_MEMBERS, $collective);

        return $this->render('collective/show.html.twig', [
            'collective' => $collective,
            'memberships' => $memberships->findForActiveCollective(),
            'can_manage' => $canManage,
            'invitations' => $canManage ? $invitations->findPending() : [],
            'cle_push' => $clePush,
            'styles_titres' => StyleTitres::cases(),
            'roles' => MembershipRole::cases(),
        ]);
    }

    /**
     * Accent colour, title typeface and logo, in the app, on the public pages
     * and on the line-up visuals. Admins only.
     */
    #[Route('/collectif/identite', name: 'app_collective_identite', methods: ['POST'])]
    #[IsCsrfTokenValid('identite')]
    public function identite(Request $request, IdentiteVisuelle $identite, ValidatorInterface $validator, EntityManagerInterface $entityManager): Response
    {
        $collective = $this->activeCollective->get() ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(CollectiveVoter::MANAGE_MEMBERS, $collective);

        $couleur = $collective->getCouleurAccent();
        $collective->setCouleurAccent($request->request->getString('couleur'));
        $violations = $validator->validateProperty($collective, 'couleurAccent');
        if (\count($violations) > 0) {
            $collective->setCouleurAccent($couleur);
            $this->addFlash('error', (string) $violations->get(0)->getMessage());

            return $this->redirectToRoute('app_collective', ['_fragment' => 'identite']);
        }
        $collective->setStyleTitres(StyleTitres::tryFrom($request->request->getString('style_titres')) ?? $collective->getStyleTitres());
        $entityManager->flush();

        $logo = $request->files->get('logo');
        if ($request->request->getBoolean('retirer_logo')) {
            $identite->retirerLogo($collective);
        } elseif ($logo instanceof UploadedFile) {
            try {
                $identite->changerLogo($collective, $logo);
            } catch (FichierRefuse $e) {
                $this->addFlash('error', $e->getMessage());

                return $this->redirectToRoute('app_collective', ['_fragment' => 'identite']);
            }
        }

        $this->addFlash('success', 'Identité visuelle enregistrée.');

        return $this->redirectToRoute('app_collective', ['_fragment' => 'identite']);
    }

    #[Route('/collectif/membres/{id}/role', name: 'app_collective_membre_role', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid('membre')]
    public function role(Membership $membership, Request $request, Membres $membres): Response
    {
        // Filtered: memberships of other collectives are not found.
        $this->denyAccessUnlessGranted(CollectiveVoter::MANAGE_MEMBERS, $membership->getCollective());
        $role = MembershipRole::tryFrom($request->request->getString('role')) ?? throw new BadRequestHttpException('Unknown role.');

        if ($membres->changerRole($membership, $role)) {
            $this->addFlash('success', \sprintf('%s est maintenant %s.', $membership->getUser()->getDisplayName(), mb_strtolower($role->label())));
        } else {
            $this->addFlash('error', 'Le collectif doit garder au moins un administrateur.');
        }

        return $this->redirectToRoute('app_collective', ['_fragment' => 'membres']);
    }

    /**
     * Admins remove anyone; every member can leave.
     */
    #[Route('/collectif/membres/{id}/retirer', name: 'app_collective_membre_retirer', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid('membre')]
    public function retirer(Membership $membership, Membres $membres, #[CurrentUser] User $user): Response
    {
        $quitte = $membership->getUser() === $user;
        if (!$quitte) {
            $this->denyAccessUnlessGranted(CollectiveVoter::MANAGE_MEMBERS, $membership->getCollective());
        }

        if (!$membres->retirer($membership)) {
            $this->addFlash('error', 'Le collectif doit garder au moins un administrateur.');

            return $this->redirectToRoute('app_collective', ['_fragment' => 'membres']);
        }

        if ($quitte) {
            $this->addFlash('success', \sprintf('Vous avez quitté %s.', $membership->getCollective()->getName()));

            return $this->redirectToRoute('app_home');
        }

        $this->addFlash('success', \sprintf('%s a été retiré du collectif.', $membership->getUser()->getDisplayName()));

        return $this->redirectToRoute('app_collective', ['_fragment' => 'membres']);
    }

    #[Route('/collectif/actif', name: 'app_collective_switch', methods: ['POST'])]
    #[IsCsrfTokenValid('switch-collective')]
    public function switch(#[CurrentUser] User $user, Request $request): Response
    {
        if (!$this->activeCollective->switchTo($user, $request->request->getInt('collective'))) {
            throw $this->createAccessDeniedException('Not a member of this collective.');
        }

        $this->addFlash('success', \sprintf('Collectif actif : %s.', $this->activeCollective->get()?->getName()));

        // Back to the page the selector was on; only local paths are accepted.
        $target = $request->request->getString('_target_path');
        if (str_starts_with($target, '/') && !str_starts_with($target, '//') && !str_contains($target, '\\')) {
            return $this->redirect($target);
        }

        return $this->redirectToRoute('app_collective');
    }
}
