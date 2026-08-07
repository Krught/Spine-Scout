<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\BlockedRelease;
use App\Entity\Book;
use App\Repository\BlockedReleaseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Functional tests for the per-book release blocklist: the DBAL upsert must be
 * idempotent (one row per book+source+sourceId, reason/expiry refreshed), the
 * key-set lookup must cover all three match axes (source|sourceId, url,
 * clientRef) while excluding expired rows, and purge must only remove what
 * actually expired.
 */
final class BlockedReleaseRepositoryTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private BlockedReleaseRepository $repository;
    private Book $book;

    protected function setUp(): void
    {
        self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->repository = $container->get(BlockedReleaseRepository::class);

        $this->em->createQuery('DELETE FROM ' . BlockedRelease::class)->execute();
        $this->em->createQuery('DELETE FROM ' . Book::class)->execute();

        $this->book = new Book('grimmory', 'ext-blocked-1', 'Red Rising');
        $this->em->persist($this->book);
        $this->em->flush();
    }

    public function testBlockRecordsAllThreeMatchKeys(): void
    {
        $this->repository->blockRelease(
            $this->book,
            'libgen',
            'md5-abc',
            'http',
            'https://dead.example/file.epub',
            'ffffffffffffffffffffffffffffffffffffffff',
            'All mirrors returned 404.',
        );

        $keys = $this->repository->blockedKeysForBook((int) $this->book->getId());

        self::assertArrayHasKey('libgen|md5-abc', $keys);
        self::assertArrayHasKey('https://dead.example/file.epub', $keys);
        self::assertArrayHasKey('ffffffffffffffffffffffffffffffffffffffff', $keys);

        $rows = $this->repository->findAllForList();
        self::assertCount(1, $rows);
        self::assertSame('All mirrors returned 404.', $rows[0]->getReason());
        self::assertSame('Red Rising', $rows[0]->getBook()->getTitle());
        // TTL: expires TTL_DAYS after creation.
        self::assertEquals(
            $rows[0]->getCreatedAt()->modify(sprintf('+%d days', BlockedRelease::TTL_DAYS)),
            $rows[0]->getExpiresAt(),
        );
    }

    public function testRepeatBlockIsAnUpsertThatRefreshesReasonAndExpiry(): void
    {
        $this->repository->blockRelease($this->book, 'libgen', 'md5-abc', 'http', null, null, 'first failure');

        // Age the row so the refresh of expires_at is observable.
        $this->em->getConnection()->executeStatement(
            "UPDATE blocked_releases SET expires_at = NOW() - INTERVAL '1 day'",
        );

        $this->repository->blockRelease($this->book, 'libgen', 'md5-abc', 'http', 'https://dead.example/x', null, 'second failure');

        $rows = $this->repository->findAllForList();
        self::assertCount(1, $rows, 'the same book+source+sourceId must stay one row');
        self::assertSame('second failure', $rows[0]->getReason());
        self::assertSame('https://dead.example/x', $rows[0]->getUrl());
        self::assertGreaterThan(new \DateTimeImmutable('+1 day'), $rows[0]->getExpiresAt(), 'expiry must be pushed out again');
    }

    public function testExpiredBlocksAreExcludedFromKeysAndPurgeable(): void
    {
        $this->repository->blockRelease($this->book, 'libgen', 'md5-expired', 'http', null, null, 'stale');
        $this->repository->blockRelease($this->book, 'zlibrary', 'id-live', 'http', null, null, 'fresh');
        $this->em->getConnection()->executeStatement(
            "UPDATE blocked_releases SET expires_at = NOW() - INTERVAL '1 hour' WHERE source_id = 'md5-expired'",
        );

        $keys = $this->repository->blockedKeysForBook((int) $this->book->getId());
        self::assertArrayNotHasKey('libgen|md5-expired', $keys, 'an expired block must no longer exclude the release');
        self::assertArrayHasKey('zlibrary|id-live', $keys);

        self::assertSame(1, $this->repository->purgeExpired());
        $rows = $this->repository->findAllForList();
        self::assertCount(1, $rows);
        self::assertSame('id-live', $rows[0]->getSourceId());
    }

    public function testKeysAreScopedPerBook(): void
    {
        $other = new Book('grimmory', 'ext-blocked-2', 'Golden Son');
        $this->em->persist($other);
        $this->em->flush();

        $this->repository->blockRelease($this->book, 'libgen', 'md5-abc', 'http', null, null, 'bad for book 1');

        self::assertSame([], $this->repository->blockedKeysForBook((int) $other->getId()));
    }

    public function testFindAllForListIsNewestFirst(): void
    {
        $this->repository->blockRelease($this->book, 'libgen', 'older', 'http', null, null, 'r1');
        $this->repository->blockRelease($this->book, 'libgen', 'newer', 'http', null, null, 'r2');
        $this->em->getConnection()->executeStatement(
            "UPDATE blocked_releases SET created_at = NOW() - INTERVAL '2 days' WHERE source_id = 'older'",
        );

        $rows = $this->repository->findAllForList();
        self::assertSame(['newer', 'older'], array_map(static fn (BlockedRelease $b): string => $b->getSourceId(), $rows));
    }
}
