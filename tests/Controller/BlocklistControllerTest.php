<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\BlockedRelease;
use App\Entity\Book;
use App\Entity\User;
use App\Repository\BlockedReleaseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class BlocklistControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private BlockedReleaseRepository $repository;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->repository = $container->get(BlockedReleaseRepository::class);

        $this->em->createQuery('DELETE FROM ' . BlockedRelease::class)->execute();
        $this->em->createQuery('DELETE FROM ' . Book::class)->execute();
        $this->em->createQuery('DELETE FROM ' . User::class)->execute();
        $this->seedUsers();
    }

    public function testListRendersBlockedReleases(): void
    {
        $this->seedBlock();

        $this->client->loginUser($this->loadUser('admin-blk'));
        $this->client->request('GET', '/settings/blocklist');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2.panel-title', 'Blocked releases');
        self::assertSelectorTextContains('table', 'Red Rising');
        self::assertSelectorTextContains('table', 'libgen');
        self::assertSelectorTextContains('table', 'All mirrors returned 404.');
        self::assertSelectorExists('form[action$="/unblock"] input[name="_token"]');
    }

    public function testListRendersEmptyState(): void
    {
        $this->client->loginUser($this->loadUser('admin-blk'));
        $this->client->request('GET', '/settings/blocklist');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.form-note', 'No releases are currently blocked.');
    }

    public function testUnblockDeletesTheEntry(): void
    {
        $block = $this->seedBlock();

        $this->client->loginUser($this->loadUser('admin-blk'));
        $crawler = $this->client->request('GET', '/settings/blocklist');
        $token = $crawler->filter(sprintf('form[action="/settings/blocklist/%d/unblock"] input[name="_token"]', $block->getId()))->attr('value');
        self::assertNotNull($token);

        $this->client->request('POST', sprintf('/settings/blocklist/%d/unblock', $block->getId()), ['_token' => $token]);

        self::assertResponseRedirects('/settings/blocklist');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Release unblocked.');

        $this->em->clear();
        self::assertSame([], $this->repository->findAllForList());
    }

    public function testUnblockRejectsInvalidCsrfToken(): void
    {
        $block = $this->seedBlock();

        $this->client->loginUser($this->loadUser('admin-blk'));
        $this->client->request('POST', sprintf('/settings/blocklist/%d/unblock', $block->getId()), ['_token' => 'nope']);

        self::assertResponseRedirects('/settings/blocklist');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Invalid CSRF token');

        $this->em->clear();
        self::assertCount(1, $this->repository->findAllForList());
    }

    public function testClearExpiredRemovesOnlyExpiredEntries(): void
    {
        $this->seedBlock(sourceId: 'md5-live');
        $this->seedBlock(sourceId: 'md5-expired');
        $this->em->getConnection()->executeStatement(
            "UPDATE blocked_releases SET expires_at = NOW() - INTERVAL '1 hour' WHERE source_id = 'md5-expired'",
        );

        $this->client->loginUser($this->loadUser('admin-blk'));
        $crawler = $this->client->request('GET', '/settings/blocklist');
        $token = $crawler->filter('form[action="/settings/blocklist/clear-expired"] input[name="_token"]')->attr('value');
        self::assertNotNull($token);

        $this->client->request('POST', '/settings/blocklist/clear-expired', ['_token' => $token]);

        self::assertResponseRedirects('/settings/blocklist');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Removed 1 expired block(s).');

        $this->em->clear();
        $rows = $this->repository->findAllForList();
        self::assertCount(1, $rows);
        self::assertSame('md5-live', $rows[0]->getSourceId());
    }

    public function testNonAdminUserGetsForbidden(): void
    {
        $this->client->loginUser($this->loadUser('plain-blk'));
        $this->client->request('GET', '/settings/blocklist');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $this->client->request('GET', '/settings/blocklist');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    // --- helpers ----------------------------------------------------------

    private function seedBlock(string $sourceId = 'md5-abc'): BlockedRelease
    {
        $book = $this->em->getRepository(Book::class)->findOneBy(['externalId' => 'ext-blk-1'])
            ?? new Book('grimmory', 'ext-blk-1', 'Red Rising');
        $this->em->persist($book);
        $this->em->flush();

        $this->repository->blockRelease($book, 'libgen', $sourceId, 'http', 'https://dead.example/' . $sourceId, null, 'All mirrors returned 404.');

        $block = $this->repository->findOneBy(['sourceId' => $sourceId]);
        self::assertNotNull($block);

        return $block;
    }

    private function seedUsers(): void
    {
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $admin = new User('admin-blk');
        $admin->setRoles([User::ROLE_ADMIN]);
        $admin->setPassword($hasher->hashPassword($admin, 'doesnt-matter'));
        $this->em->persist($admin);

        $plain = new User('plain-blk');
        $plain->setPassword($hasher->hashPassword($plain, 'doesnt-matter'));
        $this->em->persist($plain);

        $this->em->flush();
    }

    private function loadUser(string $username): User
    {
        $user = self::getContainer()->get('doctrine')->getRepository(User::class)->findOneBy(['username' => $username]);
        self::assertNotNull($user);

        return $user;
    }
}
