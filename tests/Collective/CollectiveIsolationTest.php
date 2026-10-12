<?php

namespace App\Tests\Collective;

use App\Collective\ActiveCollective;
use App\Entity\Collective;
use App\Entity\Membership;
use App\Entity\User;
use App\Repository\CollectiveRepository;
use App\Repository\MembershipRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Relies on AppFixtures: "jardin-des-lilas" (A) and "atelier-velo" (B).
 * Théo and Inès belong to A only, Hugo to B only, Camille and Nora to both.
 */
final class CollectiveIsolationTest extends KernelTestCase
{
    private int $collectiveA;
    private int $collectiveB;
    private Session $session;

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = static::getContainer()->get(Connection::class);
        $this->collectiveA = (int) $connection->fetchOne("SELECT id FROM collective WHERE slug = 'jardin-des-lilas'");
        $this->collectiveB = (int) $connection->fetchOne("SELECT id FROM collective WHERE slug = 'atelier-velo'");
    }

    public function testMemberOfAOnlySeesDataOfA(): void
    {
        $this->actAs('theo@example.com');

        self::assertSame(['jardin-des-lilas'], array_map(
            static fn (Collective $c): string => $c->getSlug(),
            $this->repository(CollectiveRepository::class)->findAll(),
        ));
        self::assertNull($this->repository(CollectiveRepository::class)->find($this->collectiveB));
        self::assertNull($this->repository(CollectiveRepository::class)->findOneBy(['slug' => 'atelier-velo']));

        $memberships = $this->repository(MembershipRepository::class)->findAll();
        self::assertCount(4, $memberships);
        foreach ($memberships as $membership) {
            self::assertSame($this->collectiveA, $membership->getCollective()->getId());
        }

        self::assertEqualsCanonicalizing(
            ['camille@example.com', 'nora@example.com', 'theo@example.com', 'ines@example.com'],
            array_map(static fn (User $u): string => $u->getEmail(), $this->repository(UserRepository::class)->findAll()),
        );
        self::assertNull($this->repository(UserRepository::class)->findOneBy(['email' => 'hugo@example.com']));

        $leaked = static::getContainer()->get(EntityManagerInterface::class)
            ->createQuery('SELECT COUNT(m.id) FROM '.Membership::class.' m JOIN m.collective c WHERE c.slug = :slug')
            ->setParameter('slug', 'atelier-velo')
            ->getSingleScalarResult();
        self::assertSame(0, $leaked);
    }

    public function testSessionPointingToAnotherCollectiveFallsBackToOwnCollective(): void
    {
        $this->actAs('theo@example.com', sessionCollective: $this->collectiveB);
        $active = static::getContainer()->get(ActiveCollective::class);

        self::assertSame($this->collectiveA, $active->getId());
        self::assertSame($this->collectiveA, $this->session->get(ActiveCollective::SESSION_KEY));
        self::assertNull($this->repository(CollectiveRepository::class)->find($this->collectiveB));
    }

    public function testCannotSwitchToCollectiveWithoutMembership(): void
    {
        $theo = $this->actAs('theo@example.com');
        $active = static::getContainer()->get(ActiveCollective::class);

        self::assertFalse($active->switchTo($theo, $this->collectiveB));
        self::assertSame($this->collectiveA, $active->getId());
        self::assertNull($this->repository(UserRepository::class)->findOneBy(['email' => 'hugo@example.com']));
    }

    public function testSwitchingCollectiveChangesVisibleData(): void
    {
        $camille = $this->actAs('camille@example.com');
        $active = static::getContainer()->get(ActiveCollective::class);
        self::assertSame($this->collectiveA, $active->getId());

        self::assertTrue($active->switchTo($camille, $this->collectiveB));

        foreach ($this->repository(MembershipRepository::class)->findAll() as $membership) {
            self::assertSame($this->collectiveB, $membership->getCollective()->getId());
        }
        self::assertNull($this->repository(UserRepository::class)->findOneBy(['email' => 'theo@example.com']));
        self::assertNotNull($this->repository(UserRepository::class)->findOneBy(['email' => 'hugo@example.com']));
    }

    public function testAnonymousSeesNoCollectiveData(): void
    {
        $this->pushRequest();
        static::getContainer()->get(ActiveCollective::class)->initialize();

        self::assertSame([], $this->repository(CollectiveRepository::class)->findAll());
        self::assertSame([], $this->repository(MembershipRepository::class)->findAll());
        self::assertSame([], $this->repository(UserRepository::class)->findAll());
    }

    private function actAs(string $email, ?int $sessionCollective = null): User
    {
        $user = $this->repository(UserRepository::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        static::getContainer()->get(TokenStorageInterface::class)
            ->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        $this->pushRequest();
        if (null !== $sessionCollective) {
            $this->session->set(ActiveCollective::SESSION_KEY, $sessionCollective);
        }

        static::getContainer()->get(ActiveCollective::class)->initialize();

        return $user;
    }

    private function pushRequest(): void
    {
        $this->session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($this->session);
        static::getContainer()->get(RequestStack::class)->push($request);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function repository(string $class): object
    {
        $service = static::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
