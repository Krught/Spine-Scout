<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Book;
use App\Entity\Integration;
use App\Integration\Hardcover\HardcoverClient;
use App\Mirror\MirrorListNormalizer;
use App\Repository\BookRepository;
use App\Repository\IntegrationRepository;
use App\Service\CoverCache;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Persisters\Entity\EntityPersister;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Unit tests for the Komga identity-stamp (`fp`) lifecycle and the fetch-failure
 * backoff in CoverCache. Repositories are real instances riding on a mocked Doctrine
 * persister so the production findOneBy() paths run unchanged; HTTP is MockHttpClient.
 */
final class CoverCacheTest extends TestCase
{
    private const KOMGA_ID = '42';

    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/spinescout_covers_' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->cacheDir)) {
            return;
        }
        foreach (scandir($this->cacheDir) ?: [] as $shard) {
            if ($shard === '.' || $shard === '..') {
                continue;
            }
            $dir = $this->cacheDir . '/' . $shard;
            foreach (scandir($dir) ?: [] as $f) {
                if ($f !== '.' && $f !== '..') {
                    @unlink($dir . '/' . $f);
                }
            }
            @rmdir($dir);
        }
        @rmdir($this->cacheDir);
    }

    // ---------------------------------------------------------------- fixtures

    private function komgaHash(): string
    {
        return sha1('komga:' . self::KOMGA_ID);
    }

    private function imagePath(): string
    {
        return $this->cacheDir . '/' . substr($this->komgaHash(), 0, 2) . '/' . $this->komgaHash() . '.webp';
    }

    private function metaPath(): string
    {
        return $this->cacheDir . '/' . substr($this->komgaHash(), 0, 2) . '/' . $this->komgaHash() . '.meta';
    }

    /** @param array<string, mixed> $meta */
    private function writeEntry(array $meta, ?string $imageBytes): void
    {
        @mkdir(\dirname($this->metaPath()), 0775, true);
        file_put_contents($this->metaPath(), json_encode($meta, JSON_UNESCAPED_SLASHES));
        if ($imageBytes !== null) {
            file_put_contents($this->imagePath(), $imageBytes);
        }
    }

    /** @return array<string, mixed> */
    private function readSidecar(): array
    {
        $decoded = json_decode((string) file_get_contents($this->metaPath()), true);
        self::assertIsArray($decoded);
        return $decoded;
    }

    /**
     * A real repository whose findOneBy() runs through a mocked entity persister —
     * the production query path without a database.
     *
     * @param callable(array<string, mixed>): ?object $load maps criteria to an entity
     */
    private function entityManagerFor(callable $load): EntityManagerInterface
    {
        $persister = $this->createStub(EntityPersister::class);
        $persister->method('load')->willReturnCallback(
            static fn (array $criteria): ?object => $load($criteria),
        );
        $uow = $this->createStub(UnitOfWork::class);
        $uow->method('getEntityPersister')->willReturn($persister);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getClassMetadata')->willReturnCallback(
            static fn (string $class): ClassMetadata => new ClassMetadata($class),
        );
        $em->method('getUnitOfWork')->willReturn($uow);
        return $em;
    }

    private function registryFor(EntityManagerInterface $em): ManagerRegistry
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($em);
        return $registry;
    }

    private function bookRepository(?Book $book): BookRepository
    {
        $em = $this->entityManagerFor(
            static fn (array $criteria): ?Book => ($book !== null && ($criteria['externalId'] ?? null) === $book->getExternalId()) ? $book : null,
        );
        return new BookRepository($this->registryFor($em));
    }

    /** @param array<string, Integration> $byKind */
    private function integrationRepository(array $byKind): IntegrationRepository
    {
        $em = $this->entityManagerFor(
            static fn (array $criteria): ?Integration => $byKind[$criteria['kind'] ?? ''] ?? null,
        );
        return new IntegrationRepository($this->registryFor($em), new MirrorListNormalizer());
    }

    /** @param array<string, Integration> $integrations keyed by Integration::KIND_* */
    private function cache(HttpClientInterface $http, ?Book $book, array $integrations = []): CoverCache
    {
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('/cache/cover/x');
        return new CoverCache(
            $this->cacheDir,
            $http,
            $this->integrationRepository($integrations),
            $urls,
            $this->bookRepository($book),
            new HardcoverClient($http, new ArrayAdapter()),
        );
    }

    private function grimmoryBook(?string $isbn, string $title = 'Red Rising', ?string $author = 'Pierce Brown'): Book
    {
        $book = new Book(Book::SOURCE_GRIMMORY, self::KOMGA_ID, $title);
        $book->setAuthor($author);
        $book->setIsbn($isbn);
        return $book;
    }

    private function grimmoryIntegration(): Integration
    {
        $integration = new Integration(Integration::KIND_GRIMMORY);
        $integration->setEnabled(true);
        $integration->setBaseUrl('http://komga.test');
        $integration->setCredentials(['username' => 'u', 'password' => 'p']);
        return $integration;
    }

    private function hardcoverIntegration(): Integration
    {
        $integration = new Integration(Integration::KIND_HARDCOVER);
        $integration->setEnabled(true);
        $integration->setCredentials(['token' => 'tok']);
        return $integration;
    }

    // ------------------------------------------------------------------- tests

    /** Bug 1: a pre-rework entry without an fp stamp is trusted and stamped, never deleted. */
    public function testMissingFpIsStampedAndServedWithoutRefetch(): void
    {
        $http = new MockHttpClient();
        $cache = $this->cache($http, $this->grimmoryBook('9781234567897'));
        $this->writeEntry(['kind' => 'komga', 'id' => self::KOMGA_ID], 'FAKEWEBP');

        $resolved = $cache->resolve($this->komgaHash());

        self::assertNotNull($resolved);
        self::assertSame($this->imagePath(), $resolved['path']);
        self::assertFileExists($this->imagePath());
        self::assertSame('FAKEWEBP', file_get_contents($this->imagePath()));
        self::assertSame('isbn:9781234567897', $this->readSidecar()['fp'] ?? null);
        self::assertSame(0, $http->getRequestsCount());
    }

    /** Bug 2: a ta-form stamp survives the sync backfilling an ISBN, and is upgraded in place. */
    public function testTaStampSurvivesIsbnBackfillAndIsRestamped(): void
    {
        $http = new MockHttpClient();
        $cache = $this->cache($http, $this->grimmoryBook('978-1-234-56789-7'));
        $this->writeEntry(
            ['kind' => 'komga', 'id' => self::KOMGA_ID, 'fp' => 'ta:redrising|piercebrown'],
            'FAKEWEBP',
        );

        $resolved = $cache->resolve($this->komgaHash());

        self::assertNotNull($resolved);
        self::assertFileExists($this->imagePath());
        self::assertSame('isbn:9781234567897', $this->readSidecar()['fp'] ?? null);
        self::assertSame(0, $http->getRequestsCount());
    }

    /** The ta stamp on a book still without an ISBN stays valid and untouched. */
    public function testTaStampStillValidWhenBookHasNoIsbn(): void
    {
        $http = new MockHttpClient();
        $cache = $this->cache($http, $this->grimmoryBook(null));
        $this->writeEntry(
            ['kind' => 'komga', 'id' => self::KOMGA_ID, 'fp' => 'ta:redrising|piercebrown'],
            'FAKEWEBP',
        );

        self::assertNotNull($cache->resolve($this->komgaHash()));
        self::assertSame('ta:redrising|piercebrown', $this->readSidecar()['fp'] ?? null);
        self::assertSame(0, $http->getRequestsCount());
    }

    /** A stamp matching neither identity form means the id was reassigned: drop and refetch. */
    public function testGenuineMismatchDropsCoverAndAttemptsRefetch(): void
    {
        // Komga 409s the thumbnail (the current outage) and no Hardcover integration
        // exists, so the refetch fails: entry gone, failure recorded for backoff.
        $http = new MockHttpClient(new MockResponse('', ['http_code' => 409]));
        $cache = $this->cache(
            $http,
            $this->grimmoryBook('9781234567897', 'A Different Book', 'Someone Else'),
            [Integration::KIND_GRIMMORY => $this->grimmoryIntegration()],
        );
        $this->writeEntry(
            ['kind' => 'komga', 'id' => self::KOMGA_ID, 'fp' => 'ta:redrising|piercebrown'],
            'STALEWEBP',
        );

        self::assertNull($cache->resolve($this->komgaHash()));
        self::assertFileDoesNotExist($this->imagePath());
        self::assertSame(1, $http->getRequestsCount());
        self::assertIsInt($this->readSidecar()['failedAt'] ?? null);
    }

    /** Bug 4: the warmAll() probe must not delete entries it merely inspects. */
    public function testWarmAllSkipsMissingFpEntryWithoutDeletingIt(): void
    {
        $http = new MockHttpClient();
        $cache = $this->cache($http, $this->grimmoryBook('9781234567897'));
        $this->writeEntry(['kind' => 'komga', 'id' => self::KOMGA_ID], 'FAKEWEBP');

        $summary = $cache->warmAll([], [self::KOMGA_ID]);

        self::assertSame(['queued' => 1, 'warmed' => 0, 'skipped' => 1, 'failed' => 0], $summary);
        self::assertFileExists($this->imagePath());
        self::assertSame(0, $http->getRequestsCount());
    }

    /** Bug 5: a failed fetch is negative-cached; the window elapsing re-enables fetching. */
    public function testFailureBackoffSkipsRefetchWithinWindow(): void
    {
        $responses = static function () {
            while (true) {
                yield new MockResponse('', ['http_code' => 409]);
            }
        };
        $http = new MockHttpClient($responses());
        $cache = $this->cache(
            $http,
            $this->grimmoryBook('9781234567897'),
            [Integration::KIND_GRIMMORY => $this->grimmoryIntegration()],
        );
        $this->writeEntry(['kind' => 'komga', 'id' => self::KOMGA_ID], null);

        self::assertNull($cache->resolve($this->komgaHash()));
        self::assertSame(1, $http->getRequestsCount());
        $failedAt = $this->readSidecar()['failedAt'] ?? null;
        self::assertIsInt($failedAt);

        // Within the window: no new upstream request.
        self::assertNull($cache->resolve($this->komgaHash()));
        self::assertSame(1, $http->getRequestsCount());

        // Age the failure past the window: fetching resumes (and fails afresh).
        $meta = $this->readSidecar();
        $meta['failedAt'] = time() - 3600;
        file_put_contents($this->metaPath(), json_encode($meta, JSON_UNESCAPED_SLASHES));

        self::assertNull($cache->resolve($this->komgaHash()));
        self::assertSame(2, $http->getRequestsCount());
        self::assertGreaterThan(time() - 60, $this->readSidecar()['failedAt'] ?? 0);
    }

    /** Bug 6: the Hardcover editions lookup gets the normalized (digits-only) ISBN. */
    public function testHardcoverFallbackUsesNormalizedIsbn(): void
    {
        $seenIsbn = null;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$seenIsbn) {
            if (str_contains($url, 'komga.test')) {
                return new MockResponse('', ['http_code' => 409]);
            }
            if (str_contains($url, 'api.hardcover.app')) {
                $body = json_decode($options['body'] ?? '', true);
                $seenIsbn = $body['variables']['isbn'] ?? null;
                return new MockResponse(
                    json_encode(['data' => ['editions' => [['book' => ['cached_image' => 'https://covers.test/rr.png']]]]]),
                    ['response_headers' => ['content-type' => 'application/json']],
                );
            }
            // The cover asset itself 404s so the test needs no GD to complete.
            return new MockResponse('', ['http_code' => 404]);
        });
        $cache = $this->cache(
            $http,
            $this->grimmoryBook('978-1-234-56789-7'),
            [
                Integration::KIND_GRIMMORY => $this->grimmoryIntegration(),
                Integration::KIND_HARDCOVER => $this->hardcoverIntegration(),
            ],
        );
        $this->writeEntry(['kind' => 'komga', 'id' => self::KOMGA_ID], null);

        self::assertNull($cache->resolve($this->komgaHash()));
        self::assertSame('9781234567897', $seenIsbn);
        self::assertSame(3, $http->getRequestsCount());
    }

    /** Full success path: mismatch → drop → live refetch → webp on disk, restamped, backoff cleared. */
    #[RequiresPhpExtension('gd')]
    public function testMismatchRefetchSucceedsRestampsAndClearsFailureMarker(): void
    {
        $im = imagecreatetruecolor(4, 4);
        ob_start();
        imagepng($im);
        $png = (string) ob_get_clean();
        imagedestroy($im);

        $http = new MockHttpClient(new MockResponse($png));
        $cache = $this->cache(
            $http,
            $this->grimmoryBook('9781234567897', 'A Different Book', 'Someone Else'),
            [Integration::KIND_GRIMMORY => $this->grimmoryIntegration()],
        );
        $this->writeEntry(
            ['kind' => 'komga', 'id' => self::KOMGA_ID, 'fp' => 'ta:redrising|piercebrown', 'failedAt' => time() - 3600],
            'STALEWEBP',
        );

        $resolved = $cache->resolve($this->komgaHash());

        self::assertNotNull($resolved);
        self::assertFileExists($this->imagePath());
        self::assertStringStartsWith('RIFF', (string) file_get_contents($this->imagePath()));
        $sidecar = $this->readSidecar();
        self::assertSame('isbn:9781234567897', $sidecar['fp'] ?? null);
        self::assertArrayNotHasKey('failedAt', $sidecar);
        self::assertSame(1, $http->getRequestsCount());
    }
}
