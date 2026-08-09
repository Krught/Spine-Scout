<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Book;
use App\Entity\FreeleechItem;
use App\Repository\FreeleechItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Functional tests for the freeleech availability table: the sweep primitives
 * (id lookup, delete-what-we-did-not-see), the resolution bookkeeping the
 * refresh handler drives, and the browse queries — whose text match must follow
 * the joined catalog row for resolved items and the MAM strings otherwise.
 */
final class FreeleechItemRepositoryTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private FreeleechItemRepository $repository;

    protected function setUp(): void
    {
        self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->repository = $container->get(FreeleechItemRepository::class);

        $this->em->createQuery('DELETE FROM ' . FreeleechItem::class)->execute();
        $this->em->createQuery('DELETE FROM ' . Book::class)->execute();
    }

    /** A globally free row — what MAM's regular freeleech set is made of. */
    private function item(int $mamId, string $title, bool $audiobook = false): FreeleechItem
    {
        $item = new FreeleechItem($mamId, $title, $audiobook);
        $item->setFree(true);
        $this->em->persist($item);
        return $item;
    }

    /** Free for VIP accounts only: `fl_vip` without MAM's global `free`. */
    private function vipItem(int $mamId, string $title, bool $audiobook = false): FreeleechItem
    {
        return $this->item($mamId, $title, $audiobook)->setFree(false)->setFlVip(true);
    }

    public function testFindByMamTorrentIdsIsKeyedByTorrentId(): void
    {
        $this->item(101, 'Red Rising');
        $this->item(102, 'Golden Son');
        $this->em->flush();

        $found = $this->repository->findByMamTorrentIds([102, 999]);

        self::assertSame([102], array_keys($found));
        self::assertSame('Golden Son', $found[102]->getTitle());
        self::assertSame([], $this->repository->findByMamTorrentIds([]));
    }

    public function testDeleteWhereMamTorrentIdNotInSweepsUnseenRows(): void
    {
        $this->item(101, 'Red Rising');
        $this->item(102, 'Golden Son');
        $this->item(103, 'Morning Star');
        $this->em->flush();

        self::assertSame(2, $this->repository->deleteWhereMamTorrentIdNotIn([102]));
        self::assertSame([102], array_keys($this->repository->findByMamTorrentIds([101, 102, 103])));

        self::assertSame(1, $this->repository->deleteWhereMamTorrentIdNotIn([]), 'an empty keep-list clears the table');
        self::assertSame([], $this->repository->findByMamTorrentIds([102]));
    }

    public function testDeleteVipOnlyDropsOnlyTheRowsNonVipsCannotGrab(): void
    {
        $this->item(101, 'Red Rising');
        // A real MAM pick: globally free *and* flagged for VIPs — part of the regular set.
        $this->item(102, 'Golden Son')->setFlVip(true);
        $this->vipItem(103, 'Morning Star');
        $this->vipItem(104, 'Iron Gold');
        $this->em->flush();

        self::assertSame(2, $this->repository->deleteVipOnly());
        self::assertSame(
            [101, 102],
            array_keys($this->repository->findByMamTorrentIds([101, 102, 103, 104])),
            'the globally free rows survive, VIP flag or not',
        );

        self::assertSame(0, $this->repository->deleteVipOnly(), 'a second pass has nothing left to drop');
    }

    public function testPendingLookupAndResolutionCounts(): void
    {
        $this->item(101, 'Red Rising');
        $this->item(102, 'Golden Son')->setResolution(FreeleechItem::RESOLUTION_RESOLVED);
        $this->item(103, 'Morning Star')->setResolution(FreeleechItem::RESOLUTION_UNMATCHED);
        $this->item(104, 'Iron Gold');
        $this->em->flush();

        $pending = $this->repository->findPending();
        self::assertCount(2, $pending);
        self::assertSame([101, 104], array_map(static fn (FreeleechItem $i) => $i->getMamTorrentId(), $pending));
        self::assertCount(1, $this->repository->findPending(1));

        self::assertSame(
            [
                FreeleechItem::RESOLUTION_PENDING   => 2,
                FreeleechItem::RESOLUTION_RESOLVED  => 1,
                FreeleechItem::RESOLUTION_UNMATCHED => 1,
            ],
            $this->repository->countByResolution(),
        );
    }

    public function testFreeleechBookIdsOnlyCoversResolvedRowsAndRespectsVip(): void
    {
        $book = new Book('hardcover', 'hc-1', 'Red Rising');
        $vipBook = new Book('hardcover', 'hc-2', 'Golden Son');
        $pickBook = new Book('hardcover', 'hc-3', 'Morning Star');
        $this->em->persist($book);
        $this->em->persist($vipBook);
        $this->em->persist($pickBook);

        $this->item(101, 'Red Rising')->setResolution(FreeleechItem::RESOLUTION_RESOLVED)->setBook($book);
        $this->vipItem(102, 'Golden Son')->setResolution(FreeleechItem::RESOLUTION_RESOLVED)->setBook($vipBook);
        // A real MAM freeleech pick: free for everyone and free for VIPs.
        $this->item(103, 'Morning Star')->setResolution(FreeleechItem::RESOLUTION_RESOLVED)->setBook($pickBook)->setFlVip(true);
        $this->item(104, 'Iron Gold')->setResolution(FreeleechItem::RESOLUTION_UNMATCHED);
        $this->em->flush();

        $regular = $this->repository->freeleechBookIds(false);
        sort($regular);
        $expectedRegular = [(int) $book->getId(), (int) $pickBook->getId()];
        sort($expectedRegular);
        self::assertSame($expectedRegular, $regular);

        $withVip = $this->repository->freeleechBookIds(true);
        sort($withVip);
        $expected = [(int) $book->getId(), (int) $vipBook->getId(), (int) $pickBook->getId()];
        sort($expected);
        self::assertSame($expected, $withVip);
    }

    public function testBrowseFiltersOnFormatAndVip(): void
    {
        $this->item(101, 'Red Rising', false);
        // MAM's picks carry both flags, so this one belongs to the default set too.
        $this->item(102, 'Golden Son', true)->setFlVip(true);
        $this->vipItem(103, 'Morning Star', true);
        $this->em->flush();

        self::assertSame(2, $this->repository->countForBrowse(null, null, false));
        self::assertSame(3, $this->repository->countForBrowse(null, null, true));
        self::assertSame(1, $this->repository->countForBrowse(true, null, false));
        self::assertSame(2, $this->repository->countForBrowse(true, null, true));

        self::assertSame(
            ['Golden Son'],
            array_map(
                static fn (FreeleechItem $i) => $i->getTitle(),
                $this->repository->pageForBrowse(true, null, false, 'title', 'asc', 0, 10),
            ),
            'a free+fl_vip pick is a regular freeleech item',
        );

        $page = $this->repository->pageForBrowse(true, null, true, 'title', 'asc', 0, 10);
        self::assertSame(
            ['Golden Son', 'Morning Star'],
            array_map(static fn (FreeleechItem $i) => $i->getTitle(), $page),
        );
    }

    public function testSearchUsesCatalogRowWhenResolvedAndMamStringsOtherwise(): void
    {
        $book = new Book('hardcover', 'hc-1', 'Red Rising');
        $book->setAuthor('Pierce Brown')->setNarrator('Tim Gerard Reynolds');
        $this->em->persist($book);

        $resolved = $this->item(101, 'Red Rising [English / EPUB]');
        $resolved->setResolution(FreeleechItem::RESOLUTION_RESOLVED)->setBook($book)->setAuthors(['Someone Else']);

        $unmatched = $this->item(102, 'Obscure Anthology [English / M4B]', true);
        $unmatched->setResolution(FreeleechItem::RESOLUTION_UNMATCHED)
            ->setAuthors(['Pierce Brown'])
            ->setNarrators(['Nobody Famous']);
        $this->em->flush();

        $byCatalogAuthor = $this->repository->pageForBrowse(null, 'pierce brown', false, 'added', 'desc', 0, 10);
        self::assertSame([101, 102], self::sortedIds($byCatalogAuthor));

        $byCatalogNarrator = $this->repository->pageForBrowse(null, 'tim gerard', false, 'added', 'desc', 0, 10);
        self::assertSame([101], self::ids($byCatalogNarrator));

        $byMamNarrator = $this->repository->pageForBrowse(null, 'nobody famous', false, 'added', 'desc', 0, 10);
        self::assertSame([102], self::ids($byMamNarrator));
        self::assertSame(1, $this->repository->countForBrowse(null, 'nobody famous', false));

        self::assertSame(
            [],
            self::ids($this->repository->pageForBrowse(null, 'someone else', false, 'added', 'desc', 0, 10)),
            'a resolved row is matched on its catalog metadata, not its MAM strings',
        );
    }

    public function testBrowseSortsAndPages(): void
    {
        $book = new Book('hardcover', 'hc-1', 'Aardvark Days');
        $book->setAuthor('Zed Author');
        $this->em->persist($book);

        $this->item(101, 'Zulu Title [English / EPUB]')
            ->setResolution(FreeleechItem::RESOLUTION_RESOLVED)
            ->setBook($book);
        $this->item(102, 'Mango Title')->setAuthors(['Alpha Author']);
        $this->em->flush();

        self::assertSame(
            [101, 102],
            self::ids($this->repository->pageForBrowse(null, null, false, 'title', 'asc', 0, 10)),
            'title sort must prefer the joined catalog title',
        );
        self::assertSame(
            [102, 101],
            self::ids($this->repository->pageForBrowse(null, null, false, 'author', 'asc', 0, 10)),
        );
        self::assertSame(
            [101],
            self::ids($this->repository->pageForBrowse(null, null, false, 'title', 'asc', 0, 1)),
        );
        self::assertSame(
            [102],
            self::ids($this->repository->pageForBrowse(null, null, false, 'title', 'asc', 1, 1)),
        );
    }

    /**
     * @param list<FreeleechItem> $items
     *
     * @return list<int>
     */
    private static function ids(array $items): array
    {
        return array_map(static fn (FreeleechItem $i) => $i->getMamTorrentId(), $items);
    }

    /**
     * @param list<FreeleechItem> $items
     *
     * @return list<int>
     */
    private static function sortedIds(array $items): array
    {
        $ids = self::ids($items);
        sort($ids);
        return $ids;
    }
}
