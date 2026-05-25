<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Integration;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Integration>
 */
final class IntegrationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Integration::class);
    }

    public function findByKind(string $kind): ?Integration
    {
        return $this->findOneBy(['kind' => $kind]);
    }

    public function getOrCreate(string $kind): Integration
    {
        return $this->findByKind($kind) ?? new Integration($kind);
    }
}
