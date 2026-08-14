<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FreeleechItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FreeleechItem>
 */
final class FreeleechItemRepository extends ServiceEntityRepository
{
    public const BROWSE_SORTS = ['trending', 'added', 'title', 'author'];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FreeleechItem::class);
    }

    /**
     * @param list<int> $mamTorrentIds
     *
     * @return array<int, FreeleechItem> keyed by mamTorrentId
     */
    public function findByMamTorrentIds(array $mamTorrentIds): array
    {
        if ($mamTorrentIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('f')
            ->where('f.mamTorrentId IN (:ids)')
            ->setParameter('ids', array_values($mamTorrentIds))
            ->getQuery()
            ->getResult();

        $out = [];
        foreach ($rows as $item) {
            /** @var FreeleechItem $item */
            $out[$item->getMamTorrentId()] = $item;
        }
        return $out;
    }

    /**
     * Drops every row the current sweep did not see — the freeleech set rotates, so anything
     * missing is no longer free. An empty keep-list means the sweep found nothing free at all.
     * Returns what it dropped so the caller can purge the rows' cached MAM thumbnails.
     *
     * @param list<int> $keepMamTorrentIds
     *
     * @return list<array{mamTorrentId: int, thumbnailUrl: ?string}> rows deleted
     */
    public function deleteWhereMamTorrentIdNotIn(array $keepMamTorrentIds): array
    {
        $qb = $this->createQueryBuilder('f')
            ->select('f.id', 'f.mamTorrentId', 'f.thumbnailUrl');

        if ($keepMamTorrentIds !== []) {
            $qb->where('f.mamTorrentId NOT IN (:ids)')
               ->setParameter('ids', array_values($keepMamTorrentIds));
        }

        return $this->deleteSelected($qb->getQuery()->getArrayResult());
    }

    /**
     * Drops the VIP-only rows — free for VIP accounts, not free for anyone else. Used when the
     * operator turns the VIP pull off: the sweep stops confirming those rows, so they would
     * otherwise sit in the shelf forever. A row that is *also* regular freeleech (free = true)
     * belongs to the regular set and is left alone; one that becomes regular later simply
     * comes back through the ordinary sweep.
     *
     * @return list<array{mamTorrentId: int, thumbnailUrl: ?string}> rows deleted
     */
    public function deleteVipOnly(): array
    {
        $rows = $this->createQueryBuilder('f')
            ->select('f.id', 'f.mamTorrentId', 'f.thumbnailUrl')
            ->where('f.flVip = true')
            ->andWhere('f.free = false')
            ->getQuery()
            ->getArrayResult();

        return $this->deleteSelected($rows);
    }

    /**
     * Second half of the select-then-delete the two sweep deletes share: removes exactly the
     * rows the predicate query returned (by id, so the returned refs are authoritative for
     * what left the table) and hands back their thumbnail refs.
     *
     * @param list<array{id: int, mamTorrentId: int, thumbnailUrl: ?string}> $rows
     *
     * @return list<array{mamTorrentId: int, thumbnailUrl: ?string}>
     */
    private function deleteSelected(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $this->getEntityManager()->createQueryBuilder()
            ->delete(FreeleechItem::class, 'f')
            ->where('f.id IN (:ids)')
            ->setParameter('ids', array_column($rows, 'id'))
            ->getQuery()
            ->execute();

        return array_map(
            static fn (array $row): array => [
                'mamTorrentId' => (int) $row['mamTorrentId'],
                'thumbnailUrl' => $row['thumbnailUrl'],
            ],
            $rows,
        );
    }

    /**
     * @return list<FreeleechItem>
     */
    public function findPending(int $limit = 200): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.resolution = :pending')
            ->setParameter('pending', FreeleechItem::RESOLUTION_PENDING)
            ->orderBy('f.id', 'ASC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();
    }

    /**
     * Always carries all three states so callers can render "N of M resolved" without
     * juggling missing keys.
     *
     * @return array{pending: int, resolved: int, unmatched: int}
     */
    public function countByResolution(): array
    {
        $rows = $this->createQueryBuilder('f')
            ->select('f.resolution AS resolution', 'COUNT(f.id) AS total')
            ->groupBy('f.resolution')
            ->getQuery()
            ->getArrayResult();

        $out = [
            FreeleechItem::RESOLUTION_PENDING   => 0,
            FreeleechItem::RESOLUTION_RESOLVED  => 0,
            FreeleechItem::RESOLUTION_UNMATCHED => 0,
        ];
        foreach ($rows as $row) {
            $key = (string) ($row['resolution'] ?? '');
            if (array_key_exists($key, $out)) {
                $out[$key] = (int) $row['total'];
            }
        }
        return $out;
    }

    /**
     * Book ids that are currently freeleech, for the coin badge. With $includeVip false the
     * VIP-only picks are left out, since they are not free for a non-VIP account.
     *
     * @return list<int>
     */
    public function freeleechBookIds(bool $includeVip): array
    {
        $qb = $this->createQueryBuilder('f')
            ->select('IDENTITY(f.book) AS book_id')
            ->where('f.resolution = :resolved')
            ->andWhere('f.book IS NOT NULL')
            ->setParameter('resolved', FreeleechItem::RESOLUTION_RESOLVED);

        self::applyVipFilter($qb, $includeVip);

        $out = [];
        foreach ($qb->getQuery()->getArrayResult() as $row) {
            $id = (int) $row['book_id'];
            $out[$id] = true;
        }
        return array_map('intval', array_keys($out));
    }

    /**
     * One page of the freeleech shelf, with the resolved catalog row join-fetched so cards
     * shape without a query per item.
     *
     * @return list<FreeleechItem>
     */
    public function pageForBrowse(
        ?bool $audiobook,
        ?string $q,
        bool $includeVip,
        string $sort,
        string $dir,
        int $offset,
        int $limit,
    ): array {
        $direction = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';
        if (!in_array($sort, self::BROWSE_SORTS, true)) {
            $sort = 'added';
        }

        $qb = $this->createQueryBuilder('f')
            ->addSelect('b')
            ->leftJoin('f.book', 'b')
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, $limit));
        $this->applyBrowseFilters($qb, $audiobook, $q, $includeVip);

        // DQL forbids COALESCE in ORDER BY, so the sort key rides along as a HIDDEN
        // pseudo-column (the BookRepository::findRecentlyAdded precedent).
        switch ($sort) {
            case 'trending':
                // Hardcover popularity (users_count captured at resolve time) first;
                // items without it — unmatched, or resolved before the column existed —
                // sink below every scored item and keep the shelf's natural
                // most-recently-seen order among themselves.
                $qb->addSelect('COALESCE(f.popularity, -1) AS HIDDEN sort_key')
                   ->orderBy('sort_key', $direction)
                   ->addOrderBy('f.lastSeenAt', 'DESC')
                   ->addOrderBy('f.firstSeenAt', 'DESC');
                break;
            case 'title':
                $qb->addSelect('LOWER(COALESCE(b.title, f.title)) AS HIDDEN sort_key')
                   ->orderBy('sort_key', $direction);
                break;
            case 'author':
                $qb->addSelect('LOWER(COALESCE(b.author, f.authorsText)) AS HIDDEN sort_key')
                   ->orderBy('sort_key', $direction);
                break;
            default:
                $qb->orderBy('f.lastSeenAt', $direction)
                   ->addOrderBy('f.firstSeenAt', $direction);
                break;
        }

        return $qb->addOrderBy('f.id', $direction)->getQuery()->getResult();
    }

    public function countForBrowse(?bool $audiobook, ?string $q, bool $includeVip): int
    {
        $qb = $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->leftJoin('f.book', 'b');
        $this->applyBrowseFilters($qb, $audiobook, $q, $includeVip);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Shared shelf filters. The text match follows the display rule: a resolved item is matched
     * on its Hardcover row (that is what its card shows), everything else on the MAM strings.
     */
    private function applyBrowseFilters(QueryBuilder $qb, ?bool $audiobook, ?string $q, bool $includeVip): void
    {
        if ($audiobook !== null) {
            $qb->andWhere('f.audiobook = :audiobook')
               ->setParameter('audiobook', $audiobook);
        }

        self::applyVipFilter($qb, $includeVip);

        $term = trim((string) $q);
        if ($term === '') {
            return;
        }

        $qb->andWhere(
            '('
            . '(f.resolution = :resolved AND b.id IS NOT NULL AND ('
            . 'LOWER(b.title) LIKE :q OR LOWER(b.author) LIKE :q OR LOWER(b.narrator) LIKE :q'
            . ')) OR '
            . '((f.resolution <> :resolved OR b.id IS NULL) AND ('
            . 'LOWER(f.title) LIKE :q OR LOWER(f.authorsText) LIKE :q OR LOWER(f.narratorsText) LIKE :q'
            . '))'
            . ')'
        )
            ->setParameter('resolved', FreeleechItem::RESOLUTION_RESOLVED)
            ->setParameter('q', '%' . mb_strtolower($term) . '%');
    }

    /**
     * MAM stamps its freeleech picks with `free` *and* `fl_vip`, so "regular" is the global free
     * flag itself; $includeVip (the operator's fetchVipFreeleech setting) widens the set to
     * everything a VIP account also gets for nothing.
     */
    private static function applyVipFilter(QueryBuilder $qb, bool $includeVip): void
    {
        $qb->andWhere($includeVip ? '(f.free = true OR f.flVip = true)' : 'f.free = true');
    }
}
