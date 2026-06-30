<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
final class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function hasAny(): bool
    {
        $result = $this->createQueryBuilder('u')
            ->select('1')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result !== null;
    }

    public function findOneByUsername(string $username): ?User
    {
        return $this->findOneBy(['username' => User::normalizeUsername($username)]);
    }

    /**
     * Master first, then alphabetical — the order the /users management page lists users in.
     *
     * @return list<User>
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('u')
            ->orderBy('u.isMaster', 'DESC')
            ->addOrderBy('u.username', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
