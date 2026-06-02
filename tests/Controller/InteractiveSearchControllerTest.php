<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Integration;
use App\Entity\User;
use App\Mirror\MirrorListNormalizer;
use App\Repository\IntegrationRepository;
use App\Search\DirectDownload\DirectDownloadConfig;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class InteractiveSearchControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private IntegrationRepository $integrations;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->integrations = $container->get(IntegrationRepository::class);

        $this->em->createQuery('DELETE FROM '.Integration::class)->execute();
        $this->em->createQuery('DELETE FROM '.User::class)->execute();
        $this->seedUser();
        $this->seedConfig();
    }

    public function testRequiresLogin(): void
    {
        $this->client->request('POST', '/interactive-search/sources');
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testSourcesListsAllFourWithConfiguredMirrors(): void
    {
        $this->client->loginUser($this->loadUser());
        $token = $this->csrfToken();

        $this->postJson('/interactive-search/sources', ['_token' => $token]);

        self::assertResponseIsSuccessful();
        $data = $this->json();
        $ids = array_column($data['sources'], 'id');
        self::assertSame(['annas_archive', 'libgen', 'zlibrary', 'welib'], $ids);

        $byId = [];
        foreach ($data['sources'] as $s) {
            $byId[$s['id']] = $s;
        }
        self::assertSame(['https://aa.test'], $byId['annas_archive']['mirrors']);
        self::assertSame(['https://lg.test'], $byId['libgen']['mirrors']);
        self::assertSame([], $byId['welib']['mirrors']);
    }

    public function testSourcesFollowOperatorPriorityOrder(): void
    {
        // Operator re-prioritises Z-Library to the top; the panel must surface it
        // first so its auto-run search defaults to the highest-priority source.
        $config = DirectDownloadConfig::fromArray([
            'indexerPriority' => [
                ['id' => 'zlibrary', 'enabled' => true],
                ['id' => 'welib', 'enabled' => true],
                ['id' => 'annas_archive', 'enabled' => true],
                ['id' => 'libgen', 'enabled' => true],
            ],
            'mirrors' => ['zlibrary' => ['https://zl.test']],
        ], new MirrorListNormalizer());
        $this->integrations->saveDirectDownloadConfig($config, true, $this->em);
        $this->em->flush();

        $this->client->loginUser($this->loadUser());
        $token = $this->csrfToken();
        $this->postJson('/interactive-search/sources', ['_token' => $token]);

        self::assertResponseIsSuccessful();
        $ids = array_column($this->json()['sources'], 'id');
        self::assertSame(['zlibrary', 'welib', 'annas_archive', 'libgen'], $ids);
    }

    public function testRejectsInvalidCsrf(): void
    {
        $this->client->loginUser($this->loadUser());

        $this->postJson('/interactive-search/sources', ['_token' => 'not-a-valid-token']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testRunRejectsUnknownSource(): void
    {
        $this->client->loginUser($this->loadUser());
        $token = $this->csrfToken();

        $this->postJson('/interactive-search/run', [
            '_token' => $token,
            'source' => 'not_a_source',
            'title'  => 'Red Rising',
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    public function testRunRejectsSourceWithoutMirror(): void
    {
        $this->client->loginUser($this->loadUser());
        $token = $this->csrfToken();

        // welib has no mirrors configured, and none supplied → 400.
        $this->postJson('/interactive-search/run', [
            '_token' => $token,
            'source' => 'welib',
            'title'  => 'Red Rising',
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    /**
     * The stateful CSRF token is rendered into the book-modal partial; GET a page
     * that includes it so the client's session carries the matching token.
     */
    private function csrfToken(): string
    {
        $crawler = $this->client->request('GET', '/browse');
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('[data-interactive-search-token-value]')->attr('data-interactive-search-token-value');
        self::assertNotEmpty($token);

        return $token;
    }

    /** @param array<string, mixed> $payload */
    private function postJson(string $path, array $payload): void
    {
        $this->client->request('POST', $path, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function seedUser(): void
    {
        $hasher = self::getContainer()->get('security.user_password_hasher');
        $user = new User('member');
        $user->setPassword($hasher->hashPassword($user, 'doesnt-matter'));
        $this->em->persist($user);
        $this->em->flush();
    }

    private function seedConfig(): void
    {
        $config = DirectDownloadConfig::fromArray([
            'indexerPriority' => [
                ['id' => 'annas_archive', 'enabled' => true],
                ['id' => 'libgen', 'enabled' => true],
                ['id' => 'zlibrary', 'enabled' => false],
                ['id' => 'welib', 'enabled' => false],
            ],
            'mirrors' => [
                'annas_archive' => ['https://aa.test'],
                'libgen'        => ['https://lg.test'],
            ],
        ], new MirrorListNormalizer());
        $this->integrations->saveDirectDownloadConfig($config, true, $this->em);
        $this->em->flush();
    }

    private function loadUser(): User
    {
        $user = self::getContainer()->get('doctrine')
            ->getRepository(User::class)
            ->findOneBy(['username' => 'member']);
        self::assertNotNull($user);

        return $user;
    }
}
