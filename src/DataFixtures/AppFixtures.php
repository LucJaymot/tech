<?php

namespace App\DataFixtures;

use App\Booking\Hospitalite;
use App\Booking\Rider;
use App\Entity\Affectation;
use App\Entity\Artiste;
use App\Entity\Benevole;
use App\Entity\Booking;
use App\Entity\Collective;
use App\Entity\Creneau;
use App\Entity\Disponibilite;
use App\Entity\GuestList;
use App\Entity\Invite;
use App\Entity\Lieu;
use App\Entity\LigneBudget;
use App\Entity\Membership;
use App\Entity\Passage;
use App\Entity\Poste;
use App\Entity\Soiree;
use App\Entity\User;
use App\Enum\CategorieBudget;
use App\Enum\LigneBudgetType;
use App\Enum\MembershipRole;
use App\Enum\ModeRemuneration;
use App\Obligations\RetroplanningGenerator;
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

    private User $theo;

    private User $camille;

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly RetroplanningGenerator $retroplanning,
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
            if ('theo' === $key) {
                $this->theo = $user;
            }
            if ('camille' === $key) {
                $this->camille = $user;
            }
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

        // Once saved: the generator looks up the soirées' existing tasks.
        foreach ($manager->getRepository(Soiree::class)->findAll() as $soiree) {
            $this->retroplanning->generer($soiree);
        }
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
        new LigneBudget($soiree, LigneBudgetType::Depense, CategorieBudget::Technique, 'Location sono', 15000);
        new LigneBudget($soiree, LigneBudgetType::Depense, CategorieBudget::Buvette, 'Achats buvette', 25000);
        new LigneBudget($soiree, LigneBudgetType::Recette, CategorieBudget::Billetterie, 'Entrées', 80000);
        new LigneBudget($soiree, LigneBudgetType::Recette, CategorieBudget::Buvette, 'Ventes buvette', 45000);
        $manager->persist($soiree);

        $this->loadProgramme($manager, $soiree);
        $this->loadBenevolat($manager, $soiree);
    }

    /**
     * Three créneaux, two assignments: 4 people still missing (Accueil 1,
     * Buvette 19 h 1, Buvette 22 h 2).
     */
    private function loadBenevolat(ObjectManager $manager, Soiree $soiree): void
    {
        $jour = $soiree->getDate();
        $accueil = new Creneau(new Poste($soiree, 'Accueil'), $jour->setTime(18, 30), $jour->setTime(22, 0), 2);
        $buvette = new Poste($soiree, 'Buvette');
        $buvetteSoir = new Creneau($buvette, $jour->setTime(19, 0), $jour->setTime(22, 0), 2);
        $buvetteNuit = new Creneau($buvette, $jour->setTime(22, 0), $jour->modify('+1 day')->setTime(1, 0), 2);

        $collective = $soiree->getCollective();
        $lea = (new Benevole($collective, 'Léa Fontaine'))->setEmail('lea@example.com')->setTelephone('06 12 34 56 78');
        $theo = (new Benevole($collective, 'Théo Lambert'))->setUser($this->theo);
        $sam = new Benevole($collective, 'Sam');
        array_map($manager->persist(...), [$lea, $theo, $sam]);

        $manager->persist(new Affectation($accueil, $lea));
        $manager->persist(new Affectation($buvetteSoir, $theo));
        $manager->persist(new Disponibilite($buvetteNuit, $lea));
        $manager->persist(new Disponibilite($buvetteNuit, $sam));
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

        // Statuses set directly: the workflow guards need a logged-in admin.
        // Confirmed bookings get their fee line from CachetsBudgetListener.
        $lilaBooking = (new Booking($soiree, $lila, 60000, ModeRemuneration::Cession))
            ->setPassage($concert)
            ->setRider(new Rider(30, 20, ['Piano numérique', '2 micros chant'], ['Guitare'], 'Retour de scène côté jardin.'))
            ->setHospitalite(new Hospitalite(2, ['végétarien'], 4));
        $kumoBooking = (new Booking($soiree, $kumo, 25000, ModeRemuneration::Guso))
            ->setPassage($b2b)
            ->setRider(new Rider(15, 0, ['2 platines CDJ', 'Table de mixage'], ['Clé USB']))
            ->setHospitalite(new Hospitalite(1, [], 2, true));
        (new Booking($soiree, $nadia, 25000, ModeRemuneration::Guso))
            ->setPassage($b2b)
            ->setHospitalite(new Hospitalite(1, ['sans gluten'], 2));

        $lilaBooking->setStatut('confirme');
        $kumoBooking->setStatut('declare_guso');

        // Guest lists, with accented names to search at the door.
        $lilaListe = GuestList::pourBooking($lilaBooking, $lilaBooking->getHospitalite()->invitations);
        new Invite($lilaListe, 'Amélie Dupré', 1);
        new Invite($lilaListe, 'Zoé Lambert');
        $kumoListe = GuestList::pourBooking($kumoBooking, $kumoBooking->getHospitalite()->invitations);
        new Invite($kumoListe, 'Hélène Pétillon', 1);
        $camilleListe = GuestList::pourMembre($soiree, $this->camille, 2);
        new Invite($camilleListe, 'Joël Œuvray');
        foreach ([$lilaListe, $kumoListe, $camilleListe] as $liste) {
            $manager->persist($liste);
            array_map($manager->persist(...), $liste->getInvites()->toArray());
        }
    }
}
