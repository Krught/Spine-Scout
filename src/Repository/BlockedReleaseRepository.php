<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BlockedRelease;
use App\Entity\Book;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Per-book release blocklist. Writes go through a DBAL upsert (never the ORM
 * unit of work) so recording a block from inside a message handler can never
 * flush a half-built entity graph — the same reasoning as FulfillmentLog.
 *
 * Non-final, unlike the sibling repositories: the cascade and torrent pipeline
 * unit tests stub {@see blockedKeysForBook()}, and PHPUnit cannot stub a final
 * class (the same testability concern SearchSettingsProvider solves for
 * IntegrationRepository).
 *
 * @extends ServiceEntityRepository<BlockedRelease>
 */
class BlockedReleaseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BlockedRelease::class);
    }

    /**
     * Set of match keys for every non-expired block of $bookId: for each block,
     * "source|sourceId" plus (when present) the raw download URL / magnet and
     * the client ref (torrent infohash) as separate entries. Consumers test
     * membership with isset().
     *
     * @return array<string, true>
     */
    public function blockedKeysForBook(int $bookId): array
    {
        /** @var list<array{source: string, sourceId: string, url: ?string, clientRef: ?string}> $rows */
        $rows = $this->createQueryBuilder('b')
            ->select('b.source', 'b.sourceId', 'b.url', 'b.clientRef')
            ->andWhere('b.book = :bookId')
            ->andWhere('b.expiresAt > :now')
            ->setParameter('bookId', $bookId)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getArrayResult();

        $keys = [];
        foreach ($rows as $row) {
            $keys[$row['source'] . '|' . $row['sourceId']] = true;
            if ($row['url'] !== null && $row['url'] !== '') {
                $keys[$row['url']] = true;
            }
            if ($row['clientRef'] !== null && $row['clientRef'] !== '') {
                $keys[$row['clientRef']] = true;
            }
        }

        return $keys;
    }

    /**
     * Record (or refresh) a block for one release of $book. Idempotent: a
     * repeat block of the same (book, source, sourceId) updates the reason,
     * url/clientRef and pushes the expiry out another TTL window. No-ops for an
     * unsaved book — there is no row to key the block on.
     */
    public function blockRelease(
        Book $book,
        string $source,
        string $sourceId,
        string $protocol,
        ?string $url,
        ?string $clientRef,
        string $reason,
    ): void {
        $bookId = $book->getId();
        if ($bookId === null) {
            return;
        }

        $now = new \DateTimeImmutable();
        $this->getEntityManager()->getConnection()->executeStatement(
            <<<'SQL'
                INSERT INTO blocked_releases (book_id, source, source_id, protocol, url, client_ref, reason, created_at, expires_at)
                VALUES (:book_id, :source, :source_id, :protocol, :url, :client_ref, :reason, :created_at, :expires_at)
                ON CONFLICT (book_id, source, source_id) DO UPDATE
                SET reason = EXCLUDED.reason,
                    url = EXCLUDED.url,
                    client_ref = EXCLUDED.client_ref,
                    expires_at = EXCLUDED.expires_at
                SQL,
            [
                'book_id'    => $bookId,
                'source'     => mb_substr($source, 0, 40),
                'source_id'  => mb_substr($sourceId, 0, 255),
                'protocol'   => mb_substr($protocol, 0, 16),
                'url'        => $url,
                'client_ref' => $clientRef !== null ? mb_substr($clientRef, 0, 64) : null,
                'reason'     => $reason,
                'created_at' => $now->format('Y-m-d H:i:s'),
                'expires_at' => $now->modify(sprintf('+%d days', BlockedRelease::TTL_DAYS))->format('Y-m-d H:i:s'),
            ],
        );
    }

    /**
     * All blocks (expired ones included, so an operator can see what just
     * lapsed), newest first, with the book fetch-joined for the list view.
     *
     * @return list<BlockedRelease>
     */
    public function findAllForList(): array
    {
        /** @var list<BlockedRelease> $rows */
        $rows = $this->createQueryBuilder('b')
            ->addSelect('book')
            ->join('b.book', 'book')
            ->orderBy('b.createdAt', 'DESC')
            ->addOrderBy('b.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /** Delete every expired block; returns the number of rows removed. */
    public function purgeExpired(): int
    {
        return (int) $this->createQueryBuilder('b')
            ->delete()
            ->andWhere('b.expiresAt <= :now')
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->execute();
    }
}
