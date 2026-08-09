<?php

declare(strict_types=1);

namespace App\Tests\Integration\Grimmory;

use App\Entity\Book;
use App\Entity\Integration;
use App\Integration\Grimmory\GrimmoryClient;
use App\Integration\Grimmory\GrimmoryLibrarySync;
use App\Repository\BookRepository;
use App\Repository\IntegrationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Format must backfill on rows whose lastModified never ticks: rows synced before
 * format capture existed (or before the mediaProfile fallback) would otherwise keep
 * a NULL format forever, because applyFromSummary() only runs on a material change.
 * Mirrors the existing every-sync ISBN re-check. A null derivation must NOT clobber
 * a known format — losing it would reclassify owned audiobooks as ebooks.
 */
final class GrimmoryLibrarySyncFormatBackfillTest extends WebTestCase
{
    private const LAST_MODIFIED = '2026-08-07T21:21:12Z';

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->em->createQuery('DELETE FROM ' . Book::class)->execute();
        $this->em->createQuery('DELETE FROM ' . Integration::class . ' i WHERE i.kind = :k')
            ->setParameter('k', Integration::KIND_GRIMMORY)
            ->execute();
    }

    public function testUnchangedRowsBackfillFormatButNullDerivationDoesNotClobber(): void
    {
        // Synced before format capture: audiobook row with NULL format.
        $audiobook = $this->seedSyncedBook('185', 'Season 1', format: null);
        // Known-format row the server no longer yields a derivable format for.
        $epub = $this->seedSyncedBook('186', 'Darkdawn', format: 'epub');

        $result = $this->sync([
            [
                'id'           => '185',
                'name'         => 'Season 1',
                'url'          => '/komga/api/v1/books/185',
                'libraryId'    => '3',
                'media'        => ['mediaType' => 'audio/*', 'mediaProfile' => 'AUDIOBOOK'],
                'lastModified' => self::LAST_MODIFIED,
            ],
            [
                'id'           => '186',
                'name'         => 'Darkdawn',
                'url'          => '/komga/api/v1/books/186',
                'libraryId'    => '3',
                'lastModified' => self::LAST_MODIFIED,
            ],
        ]);

        self::assertSame(0, $result->added);
        self::assertSame(1, $result->updated);
        self::assertSame(0, $result->removed);

        $this->em->refresh($audiobook);
        $this->em->refresh($epub);
        self::assertSame('audiobook', $audiobook->getFormat());
        self::assertSame('epub', $epub->getFormat());
    }

    /** @param list<array<string, mixed>> $rows */
    private function sync(array $rows): \App\Integration\Grimmory\SyncResult
    {
        $http = new MockHttpClient(static function (string $method, string $url) use ($rows): MockResponse {
            $body = str_contains($url, '/libraries')
                ? [['id' => '3', 'name' => 'Audiobooks']]
                : ['content' => $rows, 'last' => true];

            return new MockResponse(
                json_encode($body, \JSON_THROW_ON_ERROR),
                ['response_headers' => ['content-type' => 'application/json']],
            );
        });

        $integration = (new Integration(Integration::KIND_GRIMMORY))
            ->setBaseUrl('http://grimmory:6060/komga')
            ->setCredentials(['username' => 'u', 'password' => 'p'])
            ->setEnabled(true);
        $this->em->persist($integration);
        $this->em->flush();

        $sync = new GrimmoryLibrarySync(
            new GrimmoryClient($http),
            self::getContainer()->get(BookRepository::class),
            self::getContainer()->get(IntegrationRepository::class),
            $this->em,
        );

        return $sync->sync($integration);
    }

    /**
     * A book row as a prior sync would have left it: same lastModified the server
     * reports now, so hasMaterialChange() sees no change and only the every-sync
     * re-checks may touch it.
     */
    private function seedSyncedBook(string $externalId, string $title, ?string $format): Book
    {
        $book = new Book(Book::SOURCE_GRIMMORY, $externalId, $title);
        $book->setKomgaLibraryId('3');
        $book->setLastModifiedAt(new \DateTimeImmutable(self::LAST_MODIFIED));
        if ($format !== null) {
            $book->setFormat($format);
        }
        $this->em->persist($book);
        $this->em->flush();

        return $book;
    }
}
