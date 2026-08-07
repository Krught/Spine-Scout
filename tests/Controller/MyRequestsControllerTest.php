<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Book;
use App\Entity\BookRequest;
use App\Entity\DownloadJob;
use App\Entity\Integration;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Covers the /my-requests page: the /requests list scoped to the signed-in
 * user's own requests, including the endless-scroll JSON branch.
 */
final class MyRequestsControllerTest extends WebTestCase
{
    /** Mirrors MyRequestsController::PER_PAGE. */
    private const PER_PAGE = 50;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $c = self::getContainer();
        $this->em = $c->get(EntityManagerInterface::class);
        // Cover resolution is cache-first; a leftover entry from a sibling test
        // could otherwise leak into these renders.
        $c->get(CacheItemPoolInterface::class)->clear();

        $this->em->createQuery('DELETE FROM ' . DownloadJob::class)->execute();
        $this->em->createQuery('DELETE FROM ' . BookRequest::class)->execute();
        $this->em->createQuery('DELETE FROM ' . Book::class)->execute();
        $this->em->createQuery('DELETE FROM ' . Integration::class)->execute();
        $this->em->createQuery('DELETE FROM ' . User::class)->execute();
        $this->seedUser('mine-owner');
        $this->seedUser('other-owner');
        $this->em->clear();
    }

    public function testShowsOnlyTheCurrentUsersRequests(): void
    {
        $this->seedRequest('My Own Work', 'mine-owner');
        $this->seedRequest('Somebody Elses Work', 'other-owner');

        $this->client->loginUser($this->loadUser('mine-owner'));
        $crawler = $this->client->request('GET', '/my-requests');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('li.request-row'));
        self::assertStringContainsString('My Own Work', $this->html());
        self::assertStringNotContainsString('Somebody Elses Work', $this->html());
    }

    public function testPaginatesOwnRequestsAndAnswersTheJsonScrollFetch(): void
    {
        // One page-worth plus three own requests, so page 2 holds exactly the
        // three oldest — plus one foreign request that must never count or show.
        for ($i = 1; $i <= self::PER_PAGE + 3; $i++) {
            $this->seedRequest('Paged Work ' . $i, 'mine-owner');
        }
        $this->seedRequest('Foreign Work', 'other-owner');

        $this->client->loginUser($this->loadUser('mine-owner'));

        $page1 = $this->client->request('GET', '/my-requests');
        self::assertResponseIsSuccessful();
        self::assertCount(self::PER_PAGE, $page1->filter('li.request-row'));
        self::assertStringContainsString('data-requests-scroll-url-value="/my-requests"', $this->html());
        self::assertStringContainsString('data-requests-scroll-page-value="1"', $this->html());
        self::assertStringContainsString('data-requests-scroll-pages-value="2"', $this->html());
        self::assertCount(1, $page1->filter('.requests-scroll-sentinel'));
        self::assertStringNotContainsString('Foreign Work', $this->html());

        // The endless-scroll fetch answers the second page as row HTML.
        $this->client->request('GET', '/my-requests?page=2', [], [], ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();
        $data = $this->json();
        self::assertTrue($data['ok']);
        self::assertSame(2, $data['page']);
        self::assertSame(2, $data['pages']);
        self::assertSame(self::PER_PAGE + 3, $data['total'], 'The foreign request is not counted.');
        self::assertSame(3, substr_count($data['rows'], 'request-row'), 'Page 2 holds exactly the three oldest own rows.');
        self::assertStringContainsString('Paged Work 1<', $data['rows']);
        self::assertStringNotContainsString('Foreign Work', $data['rows']);

        // Out-of-range page values clamp into the valid range.
        $this->client->request('GET', '/my-requests?page=99');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('data-requests-scroll-page-value="2"', $this->html());
    }

    public function testEmptyStateWhenTheUserHasNoRequests(): void
    {
        $this->seedRequest('Somebody Elses Work', 'other-owner');

        $this->client->loginUser($this->loadUser('mine-owner'));
        $crawler = $this->client->request('GET', '/my-requests');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('li.request-row'));
        self::assertSelectorTextContains('.requests-empty', "You haven't requested anything yet.");
    }

    public function testAnonymousVisitorIsRedirectedToLogin(): void
    {
        $this->client->request('GET', '/my-requests');

        self::assertResponseStatusCodeSame(302);
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    private function html(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function seedRequest(string $title, string $username): BookRequest
    {
        $book = new Book('grimmory', 'ext-' . bin2hex(random_bytes(4)), $title);
        $book->setAuthor('Matt Dinniman');
        $request = new BookRequest($this->loadUser($username), $book);
        $request->setStatus(BookRequest::STATUS_APPROVED);

        $this->em->persist($book);
        $this->em->persist($request);
        $this->em->flush();

        return $request;
    }

    private function seedUser(string $username): void
    {
        $hasher = self::getContainer()->get('security.user_password_hasher');
        $user = new User($username);
        $user->setPassword($hasher->hashPassword($user, 'correct-password-123'));
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
