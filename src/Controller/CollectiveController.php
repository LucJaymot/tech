<?php

namespace App\Controller;

use App\Collective\ActiveCollective;
use App\Entity\User;
use App\Repository\InvitationRepository;
use App\Repository\MembershipRepository;
use App\Repository\SoireeRepository;
use App\Security\Voter\CollectiveVoter;
use App\Storage\FichierRefuse;
use App\Visuel\IdentiteVisuelle;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
    public function show(MembershipRepository $memberships, InvitationRepository $invitations, SoireeRepository $soirees): Response
    {
        $collective = $this->activeCollective->get();
        $canManage = null !== $collective && $this->isGranted(CollectiveVoter::MANAGE_MEMBERS, $collective);

        return $this->render('collective/show.html.twig', [
            'collective' => $collective,
            'memberships' => $memberships->findForActiveCollective(),
            'can_manage' => $canManage,
            'invitations' => $canManage ? $invitations->findPending() : [],
            'soirees' => $soirees->findBy([], ['date' => 'ASC']),
            'can_view_budget' => null !== $collective && $this->isGranted(CollectiveVoter::VIEW_BUDGET, $collective),
            'can_edit' => null !== $collective && $this->isGranted(CollectiveVoter::EDIT, $collective),
        ]);
    }

    /**
     * Accent colour and logo of the line-up visuals. Admins only.
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
        $entityManager->flush();

        $logo = $request->files->get('logo');
        if ($logo instanceof UploadedFile) {
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
