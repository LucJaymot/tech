<?php

namespace App\Tests\Support;

use App\Collective\ActiveCollective;
use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * For kernel tests: logs a fixture user in, as the firewall would, and makes
 * their first collective (or $collectiveSlug) the active one.
 */
trait ActsAsMember
{
    protected function actAs(string $email, ?string $collectiveSlug = null): User
    {
        $container = static::getContainer();
        $container->get(ActiveCollective::class)->reset();

        $user = $container->get(UserRepository::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);
        $container->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $container->get(RequestStack::class)->push($request);

        $activeCollective = $container->get(ActiveCollective::class);
        $activeCollective->initialize();
        if (null !== $collectiveSlug) {
            $collectiveId = (int) $container->get('doctrine.dbal.default_connection')->fetchOne('SELECT id FROM collective WHERE slug = ?', [$collectiveSlug]);
            self::assertTrue($activeCollective->switchTo($user, $collectiveId));
        }

        return $user;
    }
}
