<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Book;
use App\Entity\BookRequest;
use App\Entity\BookSectionEntry;
use App\Entity\DownloadJob;
use App\Entity\Integration;
use App\Entity\User;
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
        $this->em->createQuery('DELETE FROM ' . Book::class)->execute();
        $this->em->createQuery('DELETE FROM ' . Integration::class)->execute();
        $this->em->createQuery('DELETE FROM ' . User::class)->execute();
        $this->seedUser('shelf-user');
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

    private function seedUser(string $username): void
    {
        $hasher = self::getContainer()->get('security.user_password_hasher');
        $user = new User($username);
        $user->setPassword($hasher->hashPassword($user, 'x'));
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
