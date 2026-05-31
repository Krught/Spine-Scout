<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Integration;
use App\Entity\User;
use App\Repository\IntegrationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SettingsDirectDownloadControllerTest extends WebTestCase
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
        $this->seedAdmin();
    }

    public function testGetRendersFixedSourceSectionsAndDisabledIntegration(): void
    {
        $this->client->loginUser($this->loadAdmin());
        $this->client->request('GET', '/settings/direct-download');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2.panel-title', 'Direct downloads');
        // The four fixed, brand-named mirror sources always render.
        self::assertSelectorTextContains('.mirror-section__label', "Anna's Archive");
        self::assertSelectorExists('input[type="hidden"][name="mirrors[annas_archive]"]');
        self::assertSelectorExists('input[type="hidden"][name="mirrors[libgen]"]');
        self::assertSelectorExists('input[type="hidden"][name="mirrors[zlibrary]"]');
        self::assertSelectorExists('input[type="hidden"][name="mirrors[welib]"]');
        self::assertSelectorExists('input[name="enabled"]');
    }

    public function testPostPersistsMirrorsAndPriorityForKnownSources(): void
    {
        $this->client->loginUser($this->loadAdmin());
        $token = $this->fetchCsrfToken('/settings/direct-download');

        $this->client->request('POST', '/settings/direct-download', [
            '_token'          => $token,
            'enabled'         => '1',
            'indexerPriority' => json_encode([
                ['id' => 'annas_archive', 'enabled' => true],
                ['id' => 'libgen', 'enabled' => false],
                ['id' => 'bogus', 'enabled' => true], // unknown id — must be dropped
                ['id' => 'zlibrary', 'enabled' => true],
                ['id' => 'welib', 'enabled' => true],
            ]),
            // Mirror blobs come from the token input's newline-joined hidden field.
            'mirrors' => [
                'annas_archive' => "mirror-a.example\nhttps://mirror-a-2.example/",
                'libgen'        => '',
                'zlibrary'      => '',
                'welib'         => '',
            ],
        ]);

        self::assertResponseRedirects('/settings/direct-download');

        $this->em->clear();
        $config = $this->integrations->getDirectDownloadConfig();
        $integration = $this->integrations->findByKind(Integration::KIND_DIRECT_DOWNLOAD);
        self::assertNotNull($integration);
        self::assertTrue($integration->isEnabled());

        self::assertSame(
            ['https://mirror-a.example', 'https://mirror-a-2.example'],
            $config->mirrorsFor('annas_archive')->toArray(),
        );
        self::assertTrue($config->mirrorsFor('libgen')->isEmpty());
        self::assertTrue($config->isIndexerEnabled('annas_archive'));
        self::assertFalse($config->isIndexerEnabled('libgen'));

        // Unknown ids are never persisted.
        $ids = array_column($config->indexerPriority, 'id');
        self::assertNotContains('bogus', $ids);
        self::assertContains('welib', $ids);
    }

    public function testRejectsInvalidCsrfToken(): void
    {
        $this->client->loginUser($this->loadAdmin());
        $this->client->request('POST', '/settings/direct-download', [
            '_token'  => 'nope',
            'enabled' => '1',
        ]);
        self::assertResponseRedirects('/settings/direct-download');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Invalid CSRF token');
    }

    public function testRequiresAdminRole(): void
    {
        $this->client->request('GET', '/settings/direct-download');
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    private function seedAdmin(): void
    {
        $hasher = self::getContainer()->get('security.user_password_hasher');
        $user = new User('admin-dd');
        $user->setRoles([User::ROLE_ADMIN]);
        $user->setPassword($hasher->hashPassword($user, 'doesnt-matter'));
        $this->em->persist($user);
        $this->em->flush();
    }

    private function loadAdmin(): User
    {
        $user = self::getContainer()->get('doctrine')
            ->getRepository(User::class)
            ->findOneBy(['username' => 'admin-dd']);
        self::assertNotNull($user);
        return $user;
    }

    /**
     * Stateless CSRF tokens are derived from request context, so the test must
     * GET the form first and pull the rendered token out of the response.
     */
    private function fetchCsrfToken(string $path): string
    {
        $crawler = $this->client->request('GET', $path);
        $token = $crawler->filter('input[name="_token"]')->attr('value');
        self::assertNotNull($token, "Expected CSRF token rendered at {$path}");
        return $token;
    }
}
