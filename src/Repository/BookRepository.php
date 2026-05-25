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
     * @return array<string, true>
     */
    public function downloadedTitleAuthorKeys(): array
    {
        $rows = $this->createQueryBuilder('b')
            ->select('b.title', 'b.author')
            ->where('b.removedAt IS NULL')
            ->andWhere('b.downloaded = true')
            ->getQuery()
            ->getArrayResult();

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
    public function downloadedIsbns(): array
    {
        $rows = $this->createQueryBuilder('b')
            ->select('b.isbn')
            ->where('b.removedAt IS NULL')
            ->andWhere('b.downloaded = true')
            ->andWhere('b.isbn IS NOT NULL')
            ->getQuery()
            ->getArrayResult();

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
