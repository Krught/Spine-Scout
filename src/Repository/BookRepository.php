<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Book;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Book>
 */
final class BookRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Book::class);
    }

    /**
     * @return array<string, Book> keyed by externalId
     */
    public function findAllBySourceKeyedByExternalId(string $source): array
    {
        $rows = $this->createQueryBuilder('b')
            ->where('b.source = :s')
            ->setParameter('s', $source)
            ->getQuery()
            ->getResult();

        $out = [];
        foreach ($rows as $book) {
            /** @var Book $book */
            $out[$book->getExternalId()] = $book;
        }
        return $out;
    }

    public function countActiveBySource(string $source): int
    {
        return (int) $this->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->where('b.source = :s')
            ->andWhere('b.removedAt IS NULL')
            ->setParameter('s', $source)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Normalization is lowercase + non-alphanumeric stripped, so "The Fifth Season" matches
     * "the fifth season:".
     *
     * When $audiobook is non-null, restrict to owned copies whose file format is (true) or is
     * not (false) an audiobook format, so the Browse "downloaded" badge can reflect the toggle.
     *
     * @return array<string, true>
     */
    public function downloadedTitleAuthorKeys(?bool $audiobook = null): array
    {
        $qb = $this->createQueryBuilder('b')
            ->select('b.title', 'b.author')
            ->where('b.removedAt IS NULL')
            ->andWhere('b.downloaded = true');
        $this->applyAudiobookFilter($qb, $audiobook);
        $rows = $qb->getQuery()->getArrayResult();

        $out = [];
        foreach ($rows as $row) {
            $key = self::normalizeTitleAuthor($row['title'] ?? '', $row['author'] ?? null);
            if ($key !== null) {
                $out[$key] = true;
            }
        }
        return $out;
    }

    public static function normalizeTitleAuthor(string $title, ?string $author): ?string
    {
        $t = preg_replace('/[^a-z0-9]+/', '', strtolower($title)) ?? '';
        if ($t === '') {
            return null;
        }
        $a = preg_replace('/[^a-z0-9]+/', '', strtolower($author ?? '')) ?? '';
        return $t . '|' . $a;
    }

    /** @return array<string, true> */
    public function downloadedIsbns(?bool $audiobook = null): array
    {
        $qb = $this->createQueryBuilder('b')
            ->select('b.isbn')
            ->where('b.removedAt IS NULL')
            ->andWhere('b.downloaded = true')
            ->andWhere('b.isbn IS NOT NULL');
        $this->applyAudiobookFilter($qb, $audiobook);
        $rows = $qb->getQuery()->getArrayResult();

        $out = [];
        foreach ($rows as $row) {
            $isbn = $row['isbn'] ?? null;
            if (is_string($isbn) && $isbn !== '') {
                $out[$isbn] = true;
            }
        }
        return $out;
    }

    /**
     * Narrow an owned-books query to audiobook (true) or non-audiobook (false) copies by file
     * format; a null $audiobook leaves the query untouched. `b.format` is stored lowercased.
     */
    private function applyAudiobookFilter(\Doctrine\ORM\QueryBuilder $qb, ?bool $audiobook): void
    {
        if ($audiobook === null) {
            return;
        }
        if ($audiobook) {
            $qb->andWhere('b.format IN (:audioFormats)');
        } else {
            $qb->andWhere('(b.format IS NULL OR b.format NOT IN (:audioFormats))');
        }
        $qb->setParameter('audioFormats', \App\Support\AudioFormat::EXTENSIONS);
    }

    /**
     * Keeps digits + uppercase 'X' (only valid as ISBN-10 check digit). Returns null on
     * non-plausible length so junk doesn't pollute the "have" index.
     */
    public static function normalizeIsbn(mixed $raw): ?string
    {
        // Hardcover (Hasura) returns numeric ISBNs as JSON ints; ISBN-10s with 'X' arrive as strings.
        if (is_int($raw)) {
            $raw = (string) $raw;
        }
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $clean = strtoupper(preg_replace('/[^0-9Xx]/', '', $raw) ?? '');
        $len = strlen($clean);
        if ($len !== 10 && $len !== 13) {
            return null;
        }
        if (str_contains(substr($clean, 0, -1), 'X')) {
            return null;
        }
        return $clean;
    }

    public function findOneBySourceAndExternalId(string $source, string $externalId): ?Book
    {
        return $this->findOneBy(['source' => $source, 'externalId' => $externalId]);
    }

    public const BROWSE_SORTS = ['title', 'released', 'author'];

    /**
     * @return list<Book>
     */
    public function findDownloadedPage(int $offset, int $limit, string $sort = 'title', string $direction = 'ASC'): array
    {
        $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        if (!in_array($sort, self::BROWSE_SORTS, true)) {
            $sort = 'title';
        }

        $qb = $this->createQueryBuilder('b')
            ->where('b.removedAt IS NULL')
            ->andWhere('b.downloaded = true')
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, $limit));

        switch ($sort) {
            case 'released':
                // publishedDate is a free-form 32-char string; lexicographic order
                // matches chronological order for ISO-ish "YYYY", "YYYY-MM", "YYYY-MM-DD".
                $qb->addSelect('b.publishedDate AS HIDDEN sort_key')
                   ->orderBy('sort_key', $direction);
                break;
            case 'title':
                $qb->addSelect('LOWER(b.title) AS HIDDEN sort_key')
                   ->orderBy('sort_key', $direction);
                break;
            case 'author':
                $qb->addSelect('LOWER(b.author) AS HIDDEN sort_key')
                   ->orderBy('sort_key', $direction);
                break;
            default:
                $qb->addSelect('LOWER(b.title) AS HIDDEN sort_key')
                   ->orderBy('sort_key', $direction);
                break;
        }

        return $qb->addOrderBy('b.id', $direction)->getQuery()->getResult();
    }

    /**
     * Books currently on a `(source, section)` shelf, ordered by rank.
     *
     * @return list<Book>
     */
    public function findBySection(string $source, string $section, int $limit = 25): array
    {
        return $this->createQueryBuilder('b')
            ->innerJoin(\App\Entity\BookSectionEntry::class, 'e', 'WITH', 'e.book = b')
            ->where('e.source = :source')
            ->andWhere('e.section = :section')
            ->setParameter('source', $source)
            ->setParameter('section', $section)
            ->orderBy('e.rank', 'ASC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();
    }

    /**
     * Upsert a non-library book by `(source, external_id)`. Preserves `downloaded=true` if it
     * was already set (we never demote a library book to metadata-only) and refreshes the
     * fields a shelf entry knows about. Returns the persisted entity. Caller must flush.
     */
    /**
     * @param list<string> $rawIsbns the full edition list from the integration; will be
     *                                normalized + deduped before storage.
     */
    public function upsertMetadataBook(
        string $source,
        string $externalId,
        string $title,
        ?string $author,
        ?string $externalUrl,
        ?string $coverUrl,
        array $rawIsbns,
        \DateTimeImmutable $now,
        bool $audiobookAvailable = false,
    ): Book {
        $book = $this->findOneBySourceAndExternalId($source, $externalId);
        if ($book === null) {
            $book = new Book($source, $externalId, $title);
            $book->setDownloaded(false);
            $this->getEntityManager()->persist($book);
        } else {
            $book->setTitle($title);
        }
        // Availability only ever flips on — a later sighting without audio editions shouldn't
        // erase a known audiobook edition.
        if ($audiobookAvailable) {
            $book->setAudiobookAvailable(true);
        }
        if ($author !== null) {
            $book->setAuthor($author);
        }
        if ($externalUrl !== null) {
            $book->setExternalUrl($externalUrl);
        }
        if ($coverUrl !== null) {
            $book->setCoverUrl($coverUrl);
        }

        $normalized = [];
        foreach ($rawIsbns as $candidate) {
            $n = self::normalizeIsbn($candidate);
            if ($n !== null) {
                $normalized[$n] = true;
            }
        }
        // PHP coerces all-digit array keys (e.g. an ISBN-13 like "9798217190065") to int,
        // so cast them back to strings before they reach Book::setIsbn()/setIsbns().
        $normalizedList = array_map('strval', array_keys($normalized));
        if ($normalizedList !== []) {
            $book->setIsbns($normalizedList);
            if ($book->getIsbn() === null) {
                $book->setIsbn($normalizedList[0]);
            }
        }

        $book->setLastSeenAt($now);
        if ($book->isRemoved()) {
            $book->setRemovedAt(null);
        }
        return $book;
    }

    public function findRecentlyAdded(int $limit = 15): array
    {
        // DQL forbids COALESCE in ORDER BY, so select it as a HIDDEN
        // pseudo-column and sort on that while still returning Book entities.
        return $this->createQueryBuilder('b')
            ->select('b', 'COALESCE(b.addedAt, b.firstSeenAt) AS HIDDEN sort_at')
            ->where('b.removedAt IS NULL')
            ->andWhere('b.downloaded = true')
            ->orderBy('sort_at', 'DESC')
            ->addOrderBy('b.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
