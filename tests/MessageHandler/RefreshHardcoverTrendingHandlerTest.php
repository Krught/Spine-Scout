<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Entity\Author;
use App\Entity\Book;
use App\Entity\BookRequest;
use App\Entity\BookSectionEntry;
use App\Entity\DownloadJob;
use App\Entity\FreeleechItem;
use App\Entity\Integration;
use App\Entity\User;
use App\Integration\Hardcover\HardcoverClient;
use App\Message\RefreshHardcoverTrending;
use App\MessageHandler\RefreshHardcoverTrendingHandler;
use App\Repository\AuthorRepository;
use App\Repository\BookRepository;
use App\Repository\BookSectionEntryRepository;
use App\Repository\IntegrationRepository;
use App\Service\CoverCache;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The homepage shelf refresh must never *lose* data on a degraded upstream: an empty (but
 * successful) Hardcover response used to reach replaceSection(), which deletes the section's
 * rows before inserting, and to reach the popular-authors rank clear, which nulls every rank
 * that carries no fresh stamp. Both now keep what we already have.
 */
final class RefreshHardcoverTrendingHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $this->em = $c->get(EntityManagerInterface::class);

        $this->em->createQuery('DELETE FROM ' . DownloadJob::class)->execute();
        $this->em->createQuery('DELETE FROM ' . BookRequest::class)->execute();
        $this->em->createQuery('DELETE FROM ' . BookSectionEntry::class)->execute();
        $this->em->createQuery('DELETE FROM ' . FreeleechItem::class)->execute();
        $this->em->createQuery('DELETE FROM ' . Book::class)->execute();
        $this->em->createQuery('DELETE FROM ' . Author::class)->execute();
        $this->em->createQuery('DELETE FROM ' . Integration::class)->execute();
        $this->em->createQuery('DELETE FROM ' . User::class)->execute();

        $integration = (new Integration(Integration::KIND_HARDCOVER))
            ->setCredentials(['token' => 'test-token'])
            ->setEnabled(true);
        $this->em->persist($integration);
        $this->em->flush();
    }

    public function testAnEmptyUpstreamResponseKeepsTheExistingShelfAndAuthorRanks(): void
    {
        $book = $this->seedSectionBook('Held Trending Book');
        $this->seedPopularAuthor('Held Author', rank: 1);

        $this->refresh($this->emptyResponses());

        self::assertSame(
            ['Held Trending Book'],
            $this->sectionTitles(BookSectionEntry::SECTION_TRENDING),
            'an empty upstream response wiped the trending shelf',
        );
        self::assertNotNull($book->getId());
        self::assertSame(1, $this->authorRank('Held Author'), 'an empty popular-authors response cleared the ranks');
    }

    public function testANonEmptyResponseStillReplacesTheShelfAndReranksAuthors(): void
    {
        $this->seedSectionBook('Old Trending Book');
        $this->seedPopularAuthor('Old Author', rank: 1);

        $this->refresh($this->emptyResponses(
            trendingIds: [11],
            books: [$this->bookRow(11, 'New Trending Book', 'new-trending-book')],
            authors: [['name' => 'New Author', 'slug' => 'new-author', 'cached_image' => null]],
        ));

        self::assertSame(['New Trending Book'], $this->sectionTitles(BookSectionEntry::SECTION_TRENDING));
        self::assertSame(1, $this->authorRank('New Author'));
        // The stale-rank clear still runs on a successful refresh.
        self::assertNull($this->authorRank('Old Author'));
    }

    /**
     * Answers each Hardcover GraphQL operation by name, so the handler's call order (which
     * varies with what each shelf query needs) can't desynchronise a positional response list.
     *
     * @param list<int> $trendingIds
     * @param list<array<string, mixed>> $books rows returned for the books-by-ids follow-up
     * @param list<array<string, mixed>> $authors
     */
    private function emptyResponses(array $trendingIds = [], array $books = [], array $authors = []): \Closure
    {
        return static function (string $method, string $url, array $options) use ($trendingIds, $books, $authors): ResponseInterface {
            $body = json_decode((string) ($options['body'] ?? '{}'), true);
            $query = is_array($body) ? (string) ($body['query'] ?? '') : '';
            $op = preg_match('/\bquery\s+([A-Za-z0-9_]+)/', $query, $m) ? $m[1] : '';
            $data = match ($op) {
                'SpineScoutTrendingIds' => ['books_trending' => ['ids' => $trendingIds]],
                'SpineScoutBooksByIds' => ['books' => $books],
                'SpineScoutPopularAuthors' => ['authors' => $authors],
                'SpineScoutGenreTags' => ['tags' => []],
                // New releases / upcoming / staff picks all read `books`.
                default => ['books' => []],
            };

            return new MockResponse(
                json_encode(['data' => $data], JSON_THROW_ON_ERROR),
                ['response_headers' => ['content-type' => 'application/json']],
            );
        };
    }

    private function refresh(\Closure $responder): void
    {
        $c = self::getContainer();
        $handler = new RefreshHardcoverTrendingHandler(
            $c->get(IntegrationRepository::class),
            new HardcoverClient(new MockHttpClient($responder), new ArrayAdapter(), new NullLogger()),
            $this->em,
            $c->get(BookRepository::class),
            $c->get(BookSectionEntryRepository::class),
            $c->get(AuthorRepository::class),
            $c->get(CoverCache::class),
            new NullLogger(),
        );
        $handler(new RefreshHardcoverTrending(force: true));
        $this->em->clear();
    }

    /** @return array<string, mixed> */
    private function bookRow(int $id, string $title, string $slug): array
    {
        return [
            'id' => $id,
            'title' => $title,
            'slug' => $slug,
            'cached_contributors' => [['author' => ['name' => 'Shelf Author']]],
            'cached_image' => null,
            'editions' => [],
        ];
    }

    private function seedSectionBook(string $title): Book
    {
        $book = new Book(Book::SOURCE_HARDCOVER, 'hc-' . bin2hex(random_bytes(4)), $title);
        $book->setAuthor('Shelf Author');
        $book->setDownloaded(false);
        $this->em->persist($book);
        $this->em->persist(new BookSectionEntry(
            Book::SOURCE_HARDCOVER,
            BookSectionEntry::SECTION_TRENDING,
            $book,
            1,
            new \DateTimeImmutable('-1 hour'),
        ));
        $this->em->flush();

        return $book;
    }

    private function seedPopularAuthor(string $name, int $rank): void
    {
        $author = new Author(Author::SOURCE_HARDCOVER, strtolower(str_replace(' ', '-', $name)), $name);
        $author->setPopularRank($rank);
        $author->setPopularFetchedAt(new \DateTimeImmutable('-1 hour'));
        $this->em->persist($author);
        $this->em->flush();
    }

    /** @return list<string> */
    private function sectionTitles(string $section): array
    {
        $entries = $this->em->getRepository(BookSectionEntry::class)
            ->findBy(['source' => Book::SOURCE_HARDCOVER, 'section' => $section], ['rank' => 'ASC']);

        return array_map(static fn (BookSectionEntry $e): string => (string) $e->getBook()->getTitle(), $entries);
    }

    private function authorRank(string $name): ?int
    {
        $author = $this->em->getRepository(Author::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(Author::class, $author);

        return $author->getPopularRank();
    }
}
