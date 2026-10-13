<?php

namespace App\Tests\Rgpd;

use App\Entity\User;
use App\EventListener\DerniereConnexionListener;
use App\Repository\UserRepository;
use App\Rgpd\ComptesInactifs;
use App\Rgpd\PurgerComptesInactifs;
use App\Tests\Support\ActsAsMember;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Relies on AppFixtures: the Jardin (Camille, Nora, Théo, Inès) with its
 * soirée, and the Atelier (Nora, Hugo, Camille).
 */
final class RgpdTest extends KernelTestCase
{
    use ActsAsMember;
    use ClockSensitiveTrait;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testExportHoldsEveryTableOfTheCollectiveButNoPassword(): void
    {
        $commande = $this->commande('app:collectif:exporter');
        $commande->execute(['slug' => 'jardin-des-lilas']);

        $commande->assertCommandIsSuccessful();
        $export = json_decode($commande->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('jardin-des-lilas', $export['collectif']['slug']);
        self::assertSame(['Concert de printemps'], array_column($export['tables']['soiree'], 'nom'));
        foreach (['ligne_budget', 'invite', 'booking', 'benevole', 'tache_admin', 'activity'] as $table) {
            self::assertNotEmpty($export['tables'][$table], $table);
        }
        self::assertSame(['camille@example.com', 'ines@example.com', 'nora@example.com', 'theo@example.com'], $this->trier(array_column($export['comptes_des_membres'], 'email')));
        self::assertStringNotContainsString('password', $commande->getDisplay());
        self::assertStringNotContainsString('hugo@example.com', $commande->getDisplay(), 'only members of this collective');
    }

    public function testExportOfAnUnknownCollectiveFails(): void
    {
        $commande = $this->commande('app:collectif:exporter');

        self::assertSame(1, $commande->execute(['slug' => 'inconnu']));
    }

    public function testDeletionRemovesDataAndFilesButKeepsAccounts(): void
    {
        $connection = static::getContainer()->get(Connection::class);
        $id = (int) $connection->fetchOne("SELECT id FROM collective WHERE slug = 'jardin-des-lilas'");
        $piecesJointes = static::getContainer()->get('pieces_jointes.storage');
        $piecesJointes->write($id.'/1/devis.pdf', '%PDF-1.4');

        $commande = $this->commande('app:collectif:supprimer');
        $commande->setInputs(['jardin']);
        self::assertSame(1, $commande->execute(['slug' => 'jardin-des-lilas']), 'wrong confirmation');
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM collective WHERE id = ?', [$id]));

        $commande->execute(['slug' => 'jardin-des-lilas', '--force' => true]);

        $commande->assertCommandIsSuccessful();
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM collective WHERE id = ?', [$id]));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM soiree WHERE collective_id = ?', [$id]));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM ligne_budget WHERE collective_id = ?', [$id]));
        self::assertFalse($piecesJointes->fileExists($id.'/1/devis.pdf'));
        self::assertSame(1, (int) $connection->fetchOne("SELECT COUNT(*) FROM \"user\" WHERE email = 'ines@example.com'"), 'accounts are kept');
        self::assertSame(1, (int) $connection->fetchOne("SELECT COUNT(*) FROM collective WHERE slug <> 'jardin-des-lilas'"), 'the Atelier is untouched');
    }

    public function testInactiveAccountsAreWarnedThenDeletedUnlessTheyLogIn(): void
    {
        static::mockTime('2026-10-14 03:00');
        $ines = $this->user('ines@example.com');
        $ines->seConnecte(new \DateTimeImmutable('2024-10-01'));
        $theo = $this->user('theo@example.com');
        $theo->seConnecte(new \DateTimeImmutable('2024-09-01'));
        $this->user('camille@example.com')->seConnecte(new \DateTimeImmutable('2026-10-01'));
        $this->entityManager()->flush();

        $this->purger();

        self::assertEquals(new \DateTimeImmutable('2026-11-13 03:00'), $this->user('ines@example.com')->getSuppressionPrevueLe());
        self::assertNull($this->user('camille@example.com')->getSuppressionPrevueLe());
        $destinataires = array_map(static fn (Email $e): string => $e->getTo()[0]->getAddress(), $this->emails());
        sort($destinataires);
        self::assertSame(['ines@example.com', 'theo@example.com'], $destinataires);
        self::assertStringContainsString('13/11/2026', (string) $this->emails()[0]->getSubject().$this->texte($this->emails()[0]));

        // Théo logs in during the notice.
        static::mockTime('2026-11-01 10:00');
        static::getContainer()->get(DerniereConnexionListener::class)($this->connexion($this->user('theo@example.com')));
        self::assertNull($this->user('theo@example.com')->getSuppressionPrevueLe());

        static::mockTime('2026-11-14 03:00');
        $this->purger();

        self::assertNull($this->trouver('ines@example.com'));
        self::assertInstanceOf(User::class, $this->trouver('theo@example.com'));
        self::assertEquals(new \DateTimeImmutable('2026-11-01 10:00'), $this->trouver('theo@example.com')->getDerniereConnexionLe());
    }

    private function purger(): void
    {
        static::getContainer()->get(ComptesInactifs::class)(new PurgerComptesInactifs());
        $this->entityManager()->clear();
    }

    private function connexion(User $user): LoginSuccessEvent
    {
        return new LoginSuccessEvent(
            $this->createStub(AuthenticatorInterface::class),
            new SelfValidatingPassport(new UserBadge($user->getEmail(), static fn (): User => $user)),
            new UsernamePasswordToken($user, 'main', $user->getRoles()),
            new Request(),
            null,
            'main',
        );
    }

    /**
     * @return list<Email>
     */
    private function emails(): array
    {
        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return array_values(array_filter(array_map(static fn ($e): mixed => $e->getMessage() instanceof SendEmailMessage ? $e->getMessage()->getMessage() : null, $transport->getSent()), static fn (mixed $m): bool => $m instanceof Email));
    }

    private function texte(Email $email): string
    {
        static::getContainer()->get('twig.mime_body_renderer')->render($email);

        return (string) $email->getTextBody();
    }

    private function user(string $email): User
    {
        $user = $this->trouver($email);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function trouver(string $email): ?User
    {
        // No active collective here: the filter is off.
        return static::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);
    }

    /**
     * @param list<mixed> $valeurs
     *
     * @return list<mixed>
     */
    private function trier(array $valeurs): array
    {
        sort($valeurs);

        return $valeurs;
    }

    private function commande(string $nom): CommandTester
    {
        self::assertNotNull(self::$kernel);

        return new CommandTester((new Application(self::$kernel))->find($nom));
    }

    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
