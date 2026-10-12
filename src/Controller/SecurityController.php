<?php

namespace App\Controller;

use App\Collective\ActiveCollective;
use App\Form\LoginLinkRequestType;
use App\Repository\UserRepository;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Security\Http\LoginLink\LoginLinkHandlerInterface;

final class SecurityController extends AbstractController
{
    #[Route('/connexion', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(
        Request $request,
        AuthenticationUtils $authenticationUtils,
        UserRepository $users,
        ActiveCollective $activeCollective,
        LoginLinkHandlerInterface $loginLinkHandler,
        MailerInterface $mailer,
        #[Target('login_link_ip')] RateLimiterFactoryInterface $loginLinkIpLimiter,
        #[Target('login_link_email')] RateLimiterFactoryInterface $loginLinkEmailLimiter,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_collective');
        }

        $form = $this->createForm(LoginLinkRequestType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $email = mb_strtolower(trim($form->get('email')->getData()));

            $byIp = $loginLinkIpLimiter->create($request->getClientIp())->consume();
            $byEmail = $loginLinkEmailLimiter->create($email)->consume();
            if (!$byIp->isAccepted() || !$byEmail->isAccepted()) {
                $retryAt = max($byIp->getRetryAfter(), $byEmail->getRetryAfter());
                $this->addFlash('error', \sprintf(
                    'Trop de demandes de connexion. Réessayez dans %d minute(s).',
                    max(1, (int) ceil(($retryAt->getTimestamp() - time()) / 60)),
                ));

                return $this->render('security/login.html.twig', ['form' => $form], new Response(status: Response::HTTP_TOO_MANY_REQUESTS));
            }

            // Anonymous visitors have no active collective: look the user up unfiltered.
            $user = $activeCollective->withoutFilter(fn () => $users->findOneBy(['email' => $email]));

            // Same response whether or not the address has an account.
            if (null !== $user) {
                $loginLink = $loginLinkHandler->createLoginLink($user, $request);

                $mailer->send((new TemplatedEmail())
                    ->to(new Address($user->getEmail(), $user->getDisplayName()))
                    ->subject('Votre lien de connexion')
                    ->htmlTemplate('emails/login_link.html.twig')
                    ->textTemplate('emails/login_link.txt.twig')
                    ->context([
                        'display_name' => $user->getDisplayName(),
                        'login_url' => $loginLink->getUrl(),
                        'expires_at' => $loginLink->getExpiresAt(),
                    ]));
            }

            $request->getSession()->set('login_link_email', $email);

            return $this->redirectToRoute('app_login_sent');
        }

        return $this->render('security/login.html.twig', [
            'form' => $form,
            'error' => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    #[Route('/connexion/envoye', name: 'app_login_sent', methods: ['GET'])]
    public function sent(Request $request): Response
    {
        return $this->render('security/login_sent.html.twig', [
            'email' => $request->getSession()->get('login_link_email'),
        ]);
    }

    /**
     * GET shows a confirmation button; the POST is handled by the login_link authenticator.
     */
    #[Route('/connexion/verification', name: 'app_login_check', methods: ['GET', 'POST'])]
    public function check(): Response
    {
        return $this->render('security/login_check.html.twig');
    }

    #[Route('/deconnexion', name: 'app_logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException('Handled by the logout key of the firewall.');
    }
}
