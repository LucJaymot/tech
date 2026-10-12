<?php

namespace App\DataFixtures;

use App\Booking\Hospitalite;
use App\Booking\Rider;
use App\Entity\Artiste;
use App\Entity\Booking;
use App\Entity\Collective;
use App\Entity\Lieu;
use App\Entity\LigneBudget;
use App\Entity\Membership;
use App\Entity\Passage;
use App\Entity\Poste;
use App\Entity\Soiree;
use App\Entity\User;
use App\Enum\LigneBudgetType;
use App\Enum\MembershipRole;
use App\Enum\ModeRemuneration;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Development data: two collectives and their members, and a soirée of the Jardin.
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

        $collectiveEntities = [];
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
            $collectiveEntities[$slug] = $collective;

            foreach ($data['members'] as $userKey => $role) {
                $manager->persist(new Membership($users[$userKey], $collective, $role));
            }
        }

        $this->loadSoirees($manager, $collectiveEntities['jardin-des-lilas']);

        $manager->flush();
    }

    private function loadSoirees(ObjectManager $manager, Collective $collective): void
    {
        $lieu = (new Lieu($collective, 'Salle des Lilas', "12 rue des Lilas\n75019 Paris", 120))
            ->setAcces('Entrée par la cour, rampe d\'accès à gauche.')
            ->setAmenagePourPublic(true);
        $manager->persist($lieu);

        $soiree = (new Soiree($collective, 'Concert de printemps', new \DateTimeImmutable('+3 weeks'), new \DateTimeImmutable('19:00'), new \DateTimeImmutable('01:00')))
            ->setLieu($lieu)
            ->setJauge(100)
            ->setBuvette(true)
            ->setArtistesPayes(true);
        new Poste($soiree, 'Accueil', new \DateTimeImmutable('18:30'), new \DateTimeImmutable('22:00'), 2);
        new Poste($soiree, 'Buvette', new \DateTimeImmutable('19:00'), new \DateTimeImmutable('01:00'), 3);
        new LigneBudget($soiree, 'Cachets des artistes', LigneBudgetType::Depense, 60000);
        new LigneBudget($soiree, 'Achats buvette', LigneBudgetType::Depense, 25000);
        new LigneBudget($soiree, 'Entrées', LigneBudgetType::Recette, 80000);
        new LigneBudget($soiree, 'Ventes buvette', LigneBudgetType::Recette, 45000);
        $manager->persist($soiree);

        $this->loadProgramme($manager, $soiree);
    }

    private function loadProgramme(ObjectManager $manager, Soiree $soiree): void
    {
        $jour = $soiree->getDate();
        $lila = new Artiste($soiree->getCollective(), 'Lila Moon');
        $kumo = (new Artiste($soiree->getCollective(), 'DJ Kumo'))->setEmail('kumo@example.com');
        $nadia = new Artiste($soiree->getCollective(), 'Nadia K');
        array_map($manager->persist(...), [$lila, $kumo, $nadia]);

        $concert = new Passage($soiree, $jour->setTime(20, 0), $jour->setTime(21, 0));
        $b2b = new Passage($soiree, $jour->setTime(23, 0), $jour->modify('+1 day')->setTime(1, 0));

        (new Booking($soiree, $lila, 60000, ModeRemuneration::Cession))
            ->setPassage($concert)
            ->setRider(new Rider(30, 20, ['Piano numérique', '2 micros chant'], ['Guitare'], 'Retour de scène côté jardin.'))
            ->setHospitalite(new Hospitalite(2, ['végétarien'], 4));
        (new Booking($soiree, $kumo, 25000, ModeRemuneration::Guso))
            ->setPassage($b2b)
            ->setRider(new Rider(15, 0, ['2 platines CDJ', 'Table de mixage'], ['Clé USB']))
            ->setHospitalite(new Hospitalite(1, [], 2, true));
        (new Booking($soiree, $nadia, 25000, ModeRemuneration::Guso))
            ->setPassage($b2b)
            ->setHospitalite(new Hospitalite(1, ['sans gluten'], 2));
    }
}
