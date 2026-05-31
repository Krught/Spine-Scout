<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FulfillmentEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FulfillmentEvent>
 */
final class FulfillmentEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FulfillmentEvent::class);
    }

    /**
     * @return list<FulfillmentEvent> newest first
     */
    public function recent(int $limit = 200): array
    {
        /** @var list<FulfillmentEvent> $rows */
        $rows = $this->createQueryBuilder('e')
            ->orderBy('e.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
