<?php

namespace App\Rgpd;

use App\Collective\ActiveCollective;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Accounts unused for app.rgpd.inactivite get an e-mail, then are deleted
 * once app.rgpd.preavis has passed, unless they log in meanwhile
 * (User::seConnecte() cancels the deletion).
 */
final class ComptesInactifs
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $entityManager,
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly ActiveCollective $activeCollective,
        private readonly ClockInterface $clock,
        #[Autowire(param: 'app.rgpd.inactivite')]
        private readonly string $inactivite,
        #[Autowire(param: 'app.rgpd.preavis')]
        private readonly string $preavis,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(PurgerComptesInactifs $message): void
    {
        $maintenant = $this->clock->now();

        // Users are filtered to the active collective otherwise.
        $this->activeCollective->withoutFilter(function () use ($maintenant): void {
            foreach ($this->users->findASupprimer($maintenant) as $user) {
                $this->entityManager->remove($user);
            }
            $this->entityManager->flush();

            $suppressionLe = $maintenant->modify('+'.$this->preavis);
            foreach ($this->users->findInactifsDepuis($maintenant->modify('-'.$this->inactivite)) as $user) {
                $user->prevoirSuppression($suppressionLe);
                $this->avertir($user, $suppressionLe);
            }
            $this->entityManager->flush();
        });
    }

    private function avertir(User $user, \DateTimeImmutable $suppressionLe): void
    {
        $this->mailer->send((new TemplatedEmail())
            ->to($user->getEmail())
            ->subject('Votre compte Caisson va être supprimé')
            ->htmlTemplate('emails/suppression_compte.html.twig')
            ->textTemplate('emails/suppression_compte.txt.twig')
            ->context([
                'suppression_le' => $suppressionLe,
                'connexion_url' => $this->urlGenerator->generate('app_login', [], UrlGeneratorInterface::ABSOLUTE_URL),
            ]));
    }
}
