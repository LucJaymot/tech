<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Used to upgrade (rehash) the user's password automatically over time.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    //    /**
    //     * @return User[] Returns an array of User objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('u')
    //            ->andWhere('u.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('u.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?User
    //    {
    //        return $this->createQueryBuilder('u')
    //            ->andWhere('u.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }

    /**
     * Accounts not used since $avant (never logged in: since their creation)
     * and not warned yet.
     *
     * @return list<User>
     */
    public function findInactifsDepuis(\DateTimeImmutable $avant): array
    {
        return $this->createQueryBuilder('u')
            ->where('COALESCE(u.derniereConnexionLe, u.createdAt) < :avant')
            ->andWhere('u.suppressionPrevueLe IS NULL')
            ->setParameter('avant', $avant)
            ->getQuery()
            ->getResult();
    }

    /**
     * Warned accounts whose notice is over.
     *
     * @return list<User>
     */
    public function findASupprimer(\DateTimeImmutable $maintenant): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.suppressionPrevueLe <= :maintenant')
            ->setParameter('maintenant', $maintenant)
            ->getQuery()
            ->getResult();
    }
}
