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
        // Source priority moved to Settings → General; this page links there instead.
        self::assertSelectorNotExists('input[name="indexerPriority"]');
        self::assertSelectorExists('a[href="/settings/general"]');
        // Ebook delivery moved to Settings → Ebooks; this page links there instead.
        self::assertSelectorNotExists('input[name="outputDirectory"]');
        self::assertSelectorNotExists('input[name="filenameTemplate"]');
        self::assertSelectorExists('a[href="/settings/ebooks"]');
    }

    public function testPostPersistsMirrorsAndPreservesStoredPriority(): void
    {
        // Priority is owned by Settings → General now: saving this page must keep
        // the stored list byte-for-byte — even if a stale/hostile form posts one.
        $this->seedDirectDownloadConfig([
            'indexerPriority' => [
                ['id' => 'libgen', 'enabled' => true],
                ['id' => 'annas_archive', 'enabled' => false],
                ['id' => 'zlibrary', 'enabled' => true],
                ['id' => 'welib', 'enabled' => false],
                ['id' => 'torrent', 'enabled' => true],
            ],
        ], enabled: true);

        $this->client->loginUser($this->loadAdmin());
        $token = $this->fetchCsrfToken('/settings/direct-download');

        $this->client->request('POST', '/settings/direct-download', [
            '_token'          => $token,
            'enabled'         => '1',
            // Not a form field on this page any more — must be ignored.
            'indexerPriority' => json_encode([['id' => 'welib', 'enabled' => true]]),
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

        // The stored priority (order + ticks) survives untouched.
        self::assertSame(
            [
                ['id' => 'libgen', 'enabled' => true],
                ['id' => 'annas_archive', 'enabled' => false],
                ['id' => 'zlibrary', 'enabled' => true],
                ['id' => 'welib', 'enabled' => false],
                ['id' => 'torrent', 'enabled' => true],
            ],
            $config->indexerPriority,
        );
    }

    public function testPostWithoutDeliveryFieldsPreservesStoredDelivery(): void
    {
        // Ebook delivery is owned by Settings → Ebooks now: this form no longer
        // posts outputDirectory/filenameTemplate, so its save must keep the
        // stored values instead of defaulting them away.
        $this->seedDirectDownloadConfig([
            'outputDirectory'  => '/library/ebooks',
            'filenameTemplate' => '{Author}/{Title}',
        ], enabled: true);

        $this->client->loginUser($this->loadAdmin());
        $this->postDirectDownload(['enabled' => '1']);

        $this->em->clear();
        $config = $this->integrations->getDirectDownloadConfig();
        self::assertSame('/library/ebooks', $config->outputDirectory);
        self::assertSame('{Author}/{Title}', $config->filenameTemplate);
    }

    public function testMasterToggleRoundTripPreservesPerSourceTicks(): void
    {
        $this->seedDirectDownloadConfig([
            'indexerPriority' => [
                ['id' => 'annas_archive', 'enabled' => true],
                ['id' => 'libgen', 'enabled' => false],
                ['id' => 'zlibrary', 'enabled' => true],
                ['id' => 'welib', 'enabled' => true],
                ['id' => 'torrent', 'enabled' => true],
            ],
            'mirrors' => ['annas_archive' => ['https://m.test']],
        ], enabled: true);

        $this->client->loginUser($this->loadAdmin());

        // Switch the master OFF (checkbox unticked → param absent).
        $this->postDirectDownload(['enabled' => null]);
        $this->em->clear();
        $config = $this->integrations->getDirectDownloadConfig();

        self::assertFalse($config->directDownloadsEnabled);
        // Every mirror source is forced off; the torrent row keeps its own tick.
        self::assertFalse($config->isIndexerEnabled('annas_archive'));
        self::assertFalse($config->isIndexerEnabled('zlibrary'));
        self::assertTrue($config->isIndexerEnabled('torrent'));
        // …but the STORED ticks are untouched.
        self::assertSame(
            ['annas_archive' => true, 'libgen' => false, 'zlibrary' => true, 'welib' => true, 'torrent' => true],
            array_column($config->indexerPriority, 'enabled', 'id'),
        );

        // Switch it back ON: the previous per-source choices are live again.
        $this->postDirectDownload(['enabled' => '1']);
        $this->em->clear();
        $config = $this->integrations->getDirectDownloadConfig();

        self::assertTrue($config->directDownloadsEnabled);
        self::assertTrue($config->isIndexerEnabled('annas_archive'));
        self::assertFalse($config->isIndexerEnabled('libgen'));
        self::assertTrue($config->isIndexerEnabled('zlibrary'));
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

    /**
     * Seed the direct_download row as the settings save path would: config blob
     * under options['config'], master switch on the enabled column.
     *
     * @param array<string, mixed> $raw
     */
    private function seedDirectDownloadConfig(array $raw, bool $enabled): void
    {
        $config = \App\Search\DirectDownload\DirectDownloadConfig::fromArray(
            $raw,
            self::getContainer()->get(\App\Mirror\MirrorListNormalizer::class),
        );
        $this->integrations->saveDirectDownloadConfig($config, $enabled, $this->em);
        $this->em->flush();
        $this->em->clear();
    }

    /**
     * POST the Direct downloads form as the page renders it (mirror fields always
     * present, no indexerPriority — that moved to Settings → General).
     *
     * @param array{enabled: string|null} $overrides
     */
    private function postDirectDownload(array $overrides): void
    {
        $params = [
            '_token'  => $this->fetchCsrfToken('/settings/direct-download'),
            'mirrors' => [
                'annas_archive' => 'https://m.test',
                'libgen'        => '',
                'zlibrary'      => '',
                'welib'         => '',
            ],
        ];
        if (($overrides['enabled'] ?? null) !== null) {
            $params['enabled'] = $overrides['enabled'];
        }

        $this->client->request('POST', '/settings/direct-download', $params);
        self::assertResponseRedirects('/settings/direct-download');
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
