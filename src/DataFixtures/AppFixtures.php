<?php

namespace App\DataFixtures;

use App\Entity\Collective;
use App\Entity\Membership;
use App\Entity\User;
use App\Enum\MembershipRole;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Development data: two collectives and their members.
 * Every account uses the password "password".
 */
class AppFixtures extends Fixture
{
    private const string PASSWORD = 'password';

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $users = [];
        foreach ([
            'camille' => 'Camille Martin',
            'nora' => 'Nora Benali',
            'theo' => 'Théo Lambert',
            'ines' => 'Inès Moreau',
            'hugo' => 'Hugo Petit',
        ] as $key => $displayName) {
            $user = new User($key.'@example.com', $displayName);
            $user->setPassword($this->passwordHasher->hashPassword($user, self::PASSWORD));
            $manager->persist($user);
            $users[$key] = $user;
        }

        $collectives = [
            'jardin-des-lilas' => [
                'name' => 'Jardin partagé des Lilas',
                'members' => [
                    'camille' => MembershipRole::Admin,
                    'nora' => MembershipRole::Member,
                    'theo' => MembershipRole::Member,
                    'ines' => MembershipRole::Reader,
                ],
            ],
            'atelier-velo' => [
                'name' => 'Atelier vélo solidaire',
                'members' => [
                    'nora' => MembershipRole::Admin,
                    'hugo' => MembershipRole::Member,
                    'camille' => MembershipRole::Reader,
                ],
            ],
        ];

        foreach ($collectives as $slug => $data) {
            $collective = new Collective($data['name'], $slug);
            $manager->persist($collective);

            foreach ($data['members'] as $userKey => $role) {
                $manager->persist(new Membership($users[$userKey], $collective, $role));
            }
        }

        $manager->flush();
    }
}
