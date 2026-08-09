<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Book;
use App\Entity\BookRequest;
use App\Entity\BookSectionEntry;
use App\Entity\DownloadJob;
use App\Entity\FreeleechItem;
use App\Entity\Integration;
use App\Entity\User;
use App\Integration\MyAnonamouse\MyAnonamouseConfig;
use App\Repository\IntegrationRepository;
use App\Service\ShelfCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Covers "See all" on the home shelves: each home row links to /browse?shelf=<slug>, and
 * the browse page + its infinite-scroll JSON endpoint stay inside that shelf's dataset.
 */
final class BrowseShelfControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $c = self::getContainer();
        $this->em = $c->get(EntityManagerInterface::class);
        $c->get(CacheItemPoolInterface::class)->clear();

        $this->em->createQuery('DELETE FROM ' . DownloadJob::class)->execute();
        $this->em->createQuery('DELETE FROM ' . BookRequest::class)->execute();
        $this->em->createQuery('DELETE FROM ' . BookSectionEntry::class)->execute();
        $this->em->createQuery('DELETE FROM ' . FreeleechItem::class)->execute();
        $this->em->createQuery('DELETE FROM ' . Book::class)->execute();
        $this->em->createQuery('DELETE FROM ' . Integration::class)->execute();
        $this->em->createQuery('DELETE FROM ' . User::class)->execute();
        $this->seedUser('shelf-user');
        $this->seedUser('freeleech-user', [User::ROLE_VIEW_FREELEECH]);
        $this->em->clear();
    }

    public function testHomeRowsLinkToTheMatchingBrowseShelf(): void
    {
        $this->client->loginUser($this->loadUser('shelf-user'));
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        foreach (['recent', 'trending', 'new-releases', 'upcoming', 'staff-picks'] as $slug) {
            self::assertStringContainsString('/browse?shelf=' . $slug, $html, "missing See all for {$slug}");
        }
        // Rows with no browsable dataset render no dead "See all" link.
        self::assertStringNotContainsString('class="row-more" href="#"', $html);
    }

    public function testShelfPageRendersShelfHeadingAndChip(): void
    {
        $this->client->loginUser($this->loadUser('shelf-user'));
        $this->client->request('GET', '/browse?shelf=new-releases');

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-browse-shelf-value="new-releases"', $html);
        self::assertStringContainsString('New Releases', $html);
        self::assertStringContainsString('browse-shelf-chip', $html);
    }

    public function testUnknownShelfFallsBackToDefaultBrowse(): void
    {
        $this->client->loginUser($this->loadUser('shelf-user'));
        $this->client->request('GET', '/browse?shelf=not-a-shelf');

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-browse-shelf-value=""', $html);
        self::assertStringContainsString('>Trending</h2>', $html);
    }

    /** The trending shelf *is* the default browse mode, so it renders as plain trending. */
    public function testTrendingShelfRendersDefaultBrowse(): void
    {
        $this->client->loginUser($this->loadUser('shelf-user'));
        $this->client->request('GET', '/browse?shelf=trending');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('data-browse-shelf-value=""', (string) $this->client->getResponse()->getContent());
    }

    public function testItemsEndpointServesTheSectionShelfInRankOrder(): void
    {
        $this->seedSectionBook('Zeta Release', 0, BookSectionEntry::SECTION_NEW_RELEASES);
        $this->seedSectionBook('Alpha Release', 1, BookSectionEntry::SECTION_NEW_RELEASES);
        $this->seedSectionBook('Other Shelf Book', 0, BookSectionEntry::SECTION_UPCOMING);

        $this->client->loginUser($this->loadUser('shelf-user'));
        $data = $this->getJson('/browse/items?shelf=new-releases&offset=0&limit=100');

        self::assertSame(['Zeta Release', 'Alpha Release'], array_column($data['items'], 'title'));
        self::assertFalse($data['has_more']);
        self::assertSame(2, $data['next_offset']);
    }

    public function testItemsEndpointPagesWithinTheShelf(): void
    {
        foreach (range(1, 3) as $i) {
            $this->seedSectionBook('Release ' . $i, $i, BookSectionEntry::SECTION_NEW_RELEASES);
        }

        $this->client->loginUser($this->loadUser('shelf-user'));
        $first = $this->getJson('/browse/items?shelf=new-releases&offset=0&limit=2');
        self::assertCount(2, $first['items']);
        self::assertTrue($first['has_more']);
        self::assertSame(2, $first['next_offset']);

        $second = $this->getJson('/browse/items?shelf=new-releases&offset=2&limit=2');
        self::assertSame(['Release 3'], array_column($second['items'], 'title'));
        self::assertFalse($second['has_more']);
    }

    public function testItemsEndpointSortsShelfByTitle(): void
    {
        $this->seedSectionBook('Zeta Release', 0, BookSectionEntry::SECTION_NEW_RELEASES);
        $this->seedSectionBook('Alpha Release', 1, BookSectionEntry::SECTION_NEW_RELEASES);

        $this->client->loginUser($this->loadUser('shelf-user'));
        $data = $this->getJson('/browse/items?shelf=new-releases&sort=title&dir=asc&limit=100');

        self::assertSame(['Alpha Release', 'Zeta Release'], array_column($data['items'], 'title'));
    }

    public function testRecentShelfServesDownloadedLibraryBooksNewestFirst(): void
    {
        $old = $this->seedLibraryBook('Old Library Book', new \DateTimeImmutable('-10 days'));
        $new = $this->seedLibraryBook('New Library Book', new \DateTimeImmutable('-1 day'));
        self::assertNotSame($old->getId(), $new->getId());
        // Metadata-only rows must not leak into the library shelf.
        $this->seedSectionBook('Metadata Only', 0, BookSectionEntry::SECTION_NEW_RELEASES);

        $this->client->loginUser($this->loadUser('shelf-user'));
        $data = $this->getJson('/browse/items?shelf=recent&limit=100');

        self::assertSame(['New Library Book', 'Old Library Book'], array_column($data['items'], 'title'));
        self::assertTrue($data['items'][0]['downloaded']);
    }

    /** An unknown slug must not 500 the JSON endpoint — it degrades to normal trending. */
    public function testItemsEndpointIgnoresUnknownShelf(): void
    {
        $this->seedSectionBook('Release One', 0, BookSectionEntry::SECTION_NEW_RELEASES);

        $this->client->loginUser($this->loadUser('shelf-user'));
        $data = $this->getJson('/browse/items?shelf=not-a-shelf&limit=100');

        // No integrations configured, so the trending pool is empty — and crucially the
        // shelf's rows are *not* served.
        self::assertSame([], $data['items']);
    }

    public function testFreeleechShelfResolvesOnlyForPermittedViewers(): void
    {
        $this->enableMyAnonamouse();
        $this->seedFreeleechItem('Free Book One');

        $this->client->loginUser($this->loadUser('freeleech-user'));
        $this->client->request('GET', '/browse?shelf=freeleech');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('data-browse-shelf-value="freeleech"', (string) $this->client->getResponse()->getContent());

        // Without the capability the slug is simply unknown — the page degrades to trending.
        $this->client->loginUser($this->loadUser('shelf-user'));
        $this->client->request('GET', '/browse?shelf=freeleech');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('data-browse-shelf-value=""', (string) $this->client->getResponse()->getContent());
    }

    public function testFreeleechShelfIsUnknownWhenTheIntegrationIsOff(): void
    {
        $this->enableMyAnonamouse(enabled: false);
        $this->client->loginUser($this->loadUser('freeleech-user'));
        $this->client->request('GET', '/browse?shelf=freeleech');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('data-browse-shelf-value=""', (string) $this->client->getResponse()->getContent());
    }

    public function testFreeleechShelfIsUnknownWhenTheBrowseShelfIsDisabled(): void
    {
        $this->enableMyAnonamouse(showBrowseShelf: false);
        $this->client->loginUser($this->loadUser('freeleech-user'));
        $this->client->request('GET', '/browse?shelf=freeleech');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('data-browse-shelf-value=""', (string) $this->client->getResponse()->getContent());
    }

    public function testFreeleechItemsEndpointServesTheShelfAndFallbackCards(): void
    {
        $this->enableMyAnonamouse();
        $this->seedFreeleechItem('Unresolved Free Book');

        $this->client->loginUser($this->loadUser('freeleech-user'));
        $data = $this->getJson('/browse/items?shelf=freeleech&limit=100');

        self::assertSame(['Unresolved Free Book'], array_column($data['items'], 'title'));
        $card = $data['items'][0];
        self::assertTrue($card['freeleech']);
        self::assertFalse($card['freeleech_vip']);
        // No modal identifiers at all, so the card renders unclickable.
        self::assertNull($card['meta_id']);
        self::assertNull($card['meta_source']);
        self::assertSame('MAM Author', $card['author']);
        self::assertFalse($data['has_more']);
    }

    public function testFreeleechItemsEndpointHidesVipPicksUnlessTheOperatorPullsThem(): void
    {
        $this->enableMyAnonamouse();
        $this->seedFreeleechItem('Regular Free Book');
        // The real shape of a MAM freeleech pick: free for everyone *and* free for VIPs.
        $this->seedFreeleechItem('Global Pick Book', flVip: true);
        $this->seedFreeleechItem('VIP Only Book', flVip: true, free: false);

        $this->client->loginUser($this->loadUser('freeleech-user'));
        $off = $this->getJson('/browse/items?shelf=freeleech&limit=100');
        self::assertSame(
            ['Global Pick Book', 'Regular Free Book'],
            self::sortedTitles($off['items']),
            'with the VIP pull off the shelf is every globally free row, VIP flag or not',
        );

        // No request parameter can widen it — only the operator's setting.
        $stillOff = $this->getJson('/browse/items?shelf=freeleech&limit=100&vip=1');
        self::assertCount(2, $stillOff['items'], '?vip is not a thing any more');
    }

    public function testFreeleechItemsEndpointShowsVipPicksWhenTheOperatorPullsThem(): void
    {
        $this->enableMyAnonamouse(fetchVipFreeleech: true);
        $this->seedFreeleechItem('Regular Free Book');
        $this->seedFreeleechItem('Global Pick Book', flVip: true);
        $this->seedFreeleechItem('VIP Only Book', flVip: true, free: false);

        $this->client->loginUser($this->loadUser('freeleech-user'));
        $on = $this->getJson('/browse/items?shelf=freeleech&limit=100');

        self::assertSame(
            ['Global Pick Book', 'Regular Free Book', 'VIP Only Book'],
            self::sortedTitles($on['items']),
            'the VIP-only pick simply appears in the shelf alongside the regular ones',
        );
    }

    public function testTheVipBadgeMarksOnlyThePicksNonVipsCannotGrab(): void
    {
        $this->enableMyAnonamouse(fetchVipFreeleech: true);
        $this->seedFreeleechItem('Global Pick Book', flVip: true);
        $this->seedFreeleechItem('VIP Only Book', flVip: true, free: false);

        $this->client->loginUser($this->loadUser('freeleech-user'));
        $data = $this->getJson('/browse/items?shelf=freeleech&limit=100');

        $vipFlags = [];
        foreach ($data['items'] as $item) {
            $vipFlags[$item['title']] = $item['freeleech_vip'];
        }
        ksort($vipFlags);
        self::assertSame(['Global Pick Book' => false, 'VIP Only Book' => true], $vipFlags);
    }

    public function testFreeleechItemsEndpointSearchesItsOwnRows(): void
    {
        $this->enableMyAnonamouse();
        $this->seedFreeleechItem('Findable Free Book');
        $this->seedFreeleechItem('Something Else');

        $this->client->loginUser($this->loadUser('freeleech-user'));
        $data = $this->getJson('/browse/items?shelf=freeleech&limit=100&q=findable');

        self::assertSame(['Findable Free Book'], array_column($data['items'], 'title'));
    }

    public function testFreeleechItemsEndpointForbidsViewersWithoutTheRole(): void
    {
        $this->enableMyAnonamouse();
        $this->client->loginUser($this->loadUser('shelf-user'));
        $this->client->request('GET', '/browse/items?shelf=freeleech&limit=100', [], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(403);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('forbidden', $body['error']);
    }

    public function testHomepageRowAppearsOnlyWhenEnabledAndPermitted(): void
    {
        $this->enableMyAnonamouse();
        $this->seedFreeleechItem('Homepage Free Book');

        $this->client->loginUser($this->loadUser('freeleech-user'));
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Homepage Free Book', $html);
        self::assertStringContainsString('/browse?shelf=freeleech', $html);

        $this->client->loginUser($this->loadUser('shelf-user'));
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Homepage Free Book', (string) $this->client->getResponse()->getContent());
    }

    public function testHomepageRowIsHiddenWhenShowOnHomepageIsOff(): void
    {
        $this->enableMyAnonamouse(showOnHomepage: false);
        $this->seedFreeleechItem('Hidden Free Book');

        $this->client->loginUser($this->loadUser('freeleech-user'));
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Hidden Free Book', (string) $this->client->getResponse()->getContent());
    }

    /** With the browse shelf off the row still renders, but without a "See all" target. */
    public function testHomepageRowDropsSeeAllWhenTheBrowseShelfIsOff(): void
    {
        $this->enableMyAnonamouse(showBrowseShelf: false);
        $this->seedFreeleechItem('Badge Only Book');

        $this->client->loginUser($this->loadUser('freeleech-user'));
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Badge Only Book', $html);
        self::assertStringNotContainsString('/browse?shelf=freeleech', $html);
    }

    public function testResolvedFreeleechBookIsCoinBadgedOnOtherShelves(): void
    {
        $this->enableMyAnonamouse();
        $book = $this->seedSectionBook('Free And Trending', 0, BookSectionEntry::SECTION_NEW_RELEASES);
        $this->seedFreeleechItem('Free And Trending', book: $book);

        $this->client->loginUser($this->loadUser('freeleech-user'));
        $data = $this->getJson('/browse/items?shelf=new-releases&limit=100');
        self::assertTrue($data['items'][0]['freeleech']);

        // The badge is capability-gated, so an ordinary viewer never sees it.
        $this->client->loginUser($this->loadUser('shelf-user'));
        $plain = $this->getJson('/browse/items?shelf=new-releases&limit=100');
        self::assertFalse($plain['items'][0]['freeleech']);
    }

    public function testShelfCatalogRejectsUnknownSlugs(): void
    {
        self::assertNull(ShelfCatalog::resolve('nope'));
        self::assertNull(ShelfCatalog::resolve(null));
        self::assertSame('Staff Picks', ShelfCatalog::resolve('staff-picks')['label']);
    }

    /** @return array<string, mixed> */
    private function getJson(string $uri): array
    {
        $this->client->request('GET', $uri, [], [], ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return list<string>
     */
    private static function sortedTitles(array $items): array
    {
        $titles = array_map(static fn (array $item): string => (string) $item['title'], $items);
        sort($titles);

        return $titles;
    }

    private function seedSectionBook(string $title, int $rank, string $section): Book
    {
        $book = new Book(Book::SOURCE_HARDCOVER, 'hc-' . bin2hex(random_bytes(4)), $title);
        $book->setAuthor('Shelf Author');
        // Metadata-only row, like BookRepository::upsertMetadataBook creates.
        $book->setDownloaded(false);
        $this->em->persist($book);
        $this->em->persist(new BookSectionEntry(Book::SOURCE_HARDCOVER, $section, $book, $rank, new \DateTimeImmutable()));
        $this->em->flush();

        return $book;
    }

    private function enableMyAnonamouse(
        bool $enabled = true,
        bool $showOnHomepage = true,
        bool $showBrowseShelf = true,
        bool $fetchVipFreeleech = false,
    ): void {
        /** @var IntegrationRepository $integrations */
        $integrations = self::getContainer()->get(IntegrationRepository::class);
        $integrations->saveMyAnonamouseConfig(
            new MyAnonamouseConfig(
                enabled: $enabled,
                showOnHomepage: $showOnHomepage,
                showBrowseShelf: $showBrowseShelf,
                fetchVipFreeleech: $fetchVipFreeleech,
            ),
            $enabled,
            $this->em,
        );
        $this->em->flush();
        $integrations->clearSettingsCache();
    }

    /**
     * MAM stamps its picks with the global `free` flag, so that is the default here; a VIP-only
     * row is the one that carries `fl_vip` without it.
     */
    private function seedFreeleechItem(string $title, bool $flVip = false, ?Book $book = null, bool $free = true): FreeleechItem
    {
        $item = new FreeleechItem(random_int(1, 100_000_000), $title, false);
        $item->setAuthors(['MAM Author']);
        $item->setFlVip($flVip);
        $item->setFree($free);
        if ($book !== null) {
            $item->setBook($book);
            $item->setResolution(FreeleechItem::RESOLUTION_RESOLVED);
        } else {
            $item->setResolution(FreeleechItem::RESOLUTION_UNMATCHED);
        }
        $this->em->persist($item);
        $this->em->flush();

        return $item;
    }

    private function seedLibraryBook(string $title, \DateTimeImmutable $addedAt): Book
    {
        $book = new Book(Book::SOURCE_GRIMMORY, 'komga-' . bin2hex(random_bytes(4)), $title);
        $book->setAuthor('Library Author');
        $book->setDownloaded(true);
        $book->setAddedAt($addedAt);
        $this->em->persist($book);
        $this->em->flush();

        return $book;
    }

    /** @param list<string> $roles */
    private function seedUser(string $username, array $roles = []): void
    {
        $hasher = self::getContainer()->get('security.user_password_hasher');
        $user = new User($username);
        $user->setPassword($hasher->hashPassword($user, 'x'));
        if ($roles !== []) {
            $user->setRoles($roles);
        }
        $this->em->persist($user);
        $this->em->flush();
    }

    private function loadUser(string $username): User
    {
        $user = self::getContainer()->get('doctrine')->getRepository(User::class)->findOneBy(['username' => $username]);
        self::assertNotNull($user);

        return $user;
    }
}
