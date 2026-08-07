<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Book;
use App\Entity\BookRequest;
use App\Entity\DownloadJob;
use App\Entity\Integration;
use App\Entity\User;
use App\Integration\Hardcover\HardcoverClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Covers /series/books, the JSON backend of the book modal's "Request series" panel:
 * auth guard, input validation, hardcover availability, and the owned/requested
 * stamping over seeded library books and user requests.
 */
final class SeriesControllerTest extends WebTestCase
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
        $this->em->createQuery('DELETE FROM ' . Book::class)->execute();
        $this->em->createQuery('DELETE FROM ' . Integration::class)->execute();
        $this->em->createQuery('DELETE FROM ' . User::class)->execute();
        $this->seedUser('series-user');
        $this->em->clear();
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $this->client->request('GET', '/series/books?name=Test+Saga');

        self::assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringContainsString('/login', $location);
    }

    public function testEmptyNameIsA400(): void
    {
        $this->client->loginUser($this->loadUser('series-user'));
        $this->client->request('GET', '/series/books?name=%20%20', [], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(400);
        self::assertFalse($this->json()['ok']);
    }

    public function testHardcoverNotConfiguredIsA503(): void
    {
        $this->client->loginUser($this->loadUser('series-user'));
        $this->client->request('GET', '/series/books?name=Test+Saga', [], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(503);
        self::assertFalse($this->json()['ok']);
    }

    public function testDisabledHardcoverIntegrationIsA503(): void
    {
        $this->seedHardcoverIntegration(enabled: false);
        $this->client->loginUser($this->loadUser('series-user'));
        $this->client->request('GET', '/series/books?name=Test+Saga', [], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(503);
        self::assertFalse($this->json()['ok']);
    }

    public function testStampsOwnedAndRequestedBooksAndSortsByPosition(): void
    {
        // Stubbed HardcoverClient must survive across the request — no kernel reboot.
        $this->client->disableReboot();
        $this->seedHardcoverIntegration();

        // Owned print copy of book #1, matched by ISBN.
        $owned = new Book(Book::SOURCE_GRIMMORY, 'komga-owned-1', 'First Book');
        $owned->setAuthor('Ann Author');
        $owned->setDownloaded(true);
        $owned->setIsbn('9780000000001');
        $this->em->persist($owned);

        // Pending request for book #2, matched by title|author (its cached row has no ISBN).
        $requestedBook = new Book(Book::SOURCE_HARDCOVER, 'second-book', 'Second Book');
        $requestedBook->setAuthor('Ann Author');
        $requestedBook->setDownloaded(false);
        $this->em->persist($requestedBook);
        $request = new BookRequest($this->loadUser('series-user'), $requestedBook);
        $request->setStatus(BookRequest::STATUS_PENDING);
        $request->setAudiobook(false);
        $this->em->persist($request);
        $this->em->flush();

        $this->stubHardcover([
            // 1) series name -> ids (top hit 7 wins)
            ['search' => ['ids' => [7]]],
            // 2) series books in popularity order; positions force a client-side re-sort.
            ['books' => [
                [
                    'id' => 3, 'title' => 'Third Book', 'slug' => 'third-book',
                    'cached_contributors' => [['author' => ['name' => 'Ann Author']]],
                    'cached_image' => ['url' => 'http://covers/3.jpg'],
                    'editions' => [],
                    'book_series' => [['position' => 3, 'series' => ['id' => 7, 'name' => 'Test Saga']]],
                ],
                [
                    'id' => 1, 'title' => 'First Book', 'slug' => 'first-book',
                    'cached_contributors' => [['author' => ['name' => 'Ann Author']]],
                    'cached_image' => ['url' => 'http://covers/1.jpg'],
                    'editions' => [['isbn_13' => '9780000000001', 'users_count' => 3]],
                    'book_series' => [['position' => 1, 'series' => ['id' => 7, 'name' => 'Test Saga']]],
                ],
                [
                    'id' => 2, 'title' => 'Second Book', 'slug' => 'second-book',
                    'cached_contributors' => [['author' => ['name' => 'Ann Author']]],
                    'cached_image' => null,
                    'editions' => [],
                    'book_series' => [['position' => 2, 'series' => ['id' => 7, 'name' => 'Test Saga']]],
                ],
            ]],
        ]);

        $this->client->loginUser($this->loadUser('series-user'));
        $this->client->request('GET', '/series/books?name=Test+Saga', [], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        $data = $this->json();
        self::assertTrue($data['ok']);
        self::assertSame('Test Saga', $data['series']);
        self::assertSame(['first-book', 'second-book', 'third-book'], array_column($data['books'], 'slug'));

        [$first, $second, $third] = $data['books'];
        self::assertTrue($first['owned']);
        self::assertNull($first['requestStatus']);
        self::assertFalse($second['owned']);
        self::assertSame('pending', $second['requestStatus']);
        self::assertFalse($third['owned']);
        self::assertNull($third['requestStatus']);
        self::assertSame(1, $first['position']);
        self::assertSame('http://covers/3.jpg', $third['coverUrl']);
        self::assertSame('Ann Author', $third['author']);
    }

    /** Ownership/status stamping is format-scoped: a print copy/request must not disable audiobook rows. */
    public function testAudiobookModeIgnoresPrintOwnershipAndRequests(): void
    {
        $this->client->disableReboot();
        $this->seedHardcoverIntegration();

        $owned = new Book(Book::SOURCE_GRIMMORY, 'komga-owned-2', 'First Book');
        $owned->setAuthor('Ann Author');
        $owned->setDownloaded(true);
        $owned->setIsbn('9780000000001');
        // format null => a print/ebook copy, invisible to audiobook-mode stamping.
        $this->em->persist($owned);
        $this->em->flush();

        $this->stubHardcover([
            ['search' => ['ids' => [7]]],
            ['books' => [[
                'id' => 1, 'title' => 'First Book', 'slug' => 'first-book',
                'cached_contributors' => [['author' => ['name' => 'Ann Author']]],
                'cached_image' => null,
                'editions' => [['isbn_13' => '9780000000001', 'users_count' => 3]],
                'book_series' => [['position' => 1, 'series' => ['id' => 7, 'name' => 'Test Saga']]],
            ]]],
        ]);

        $this->client->loginUser($this->loadUser('series-user'));
        $this->client->request('GET', '/series/books?name=Test+Saga&audiobook=1', [], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        $data = $this->json();
        self::assertTrue($data['ok']);
        self::assertFalse($data['books'][0]['owned']);
        self::assertNull($data['books'][0]['requestStatus']);
    }

    public function testUpstreamFailureIsA502(): void
    {
        $this->client->disableReboot();
        $this->seedHardcoverIntegration();

        $http = new MockHttpClient([new MockResponse('upstream broke', ['http_code' => 500])]);
        self::getContainer()->set(
            HardcoverClient::class,
            new HardcoverClient($http, new ArrayAdapter(), new NullLogger()),
        );

        $this->client->loginUser($this->loadUser('series-user'));
        $this->client->request('GET', '/series/books?name=Test+Saga', [], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(502);
        $data = $this->json();
        self::assertFalse($data['ok']);
        self::assertNotSame('', (string) $data['error']);
    }

    /** @param list<array<string, mixed>> $payloads GraphQL `data` payloads, in call order. */
    private function stubHardcover(array $payloads): void
    {
        $responses = array_map(
            static fn (array $data): MockResponse => new MockResponse(
                json_encode(['data' => $data], JSON_THROW_ON_ERROR),
                ['response_headers' => ['content-type' => 'application/json']],
            ),
            $payloads,
        );
        self::getContainer()->set(
            HardcoverClient::class,
            new HardcoverClient(new MockHttpClient($responses), new ArrayAdapter(), new NullLogger()),
        );
    }

    private function seedHardcoverIntegration(bool $enabled = true): void
    {
        $integration = (new Integration(Integration::KIND_HARDCOVER))
            ->setCredentials(['token' => 'test-token'])
            ->setEnabled($enabled);
        $this->em->persist($integration);
        $this->em->flush();
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
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
