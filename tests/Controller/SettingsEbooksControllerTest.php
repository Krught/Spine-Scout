<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Integration;
use App\Entity\User;
use App\Repository\IntegrationRepository;
use App\Search\DirectDownload\DirectDownloadConfig;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SettingsEbooksControllerTest extends WebTestCase
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

    public function testGetRendersDeliveryFields(): void
    {
        $this->client->loginUser($this->loadAdmin());
        $this->client->request('GET', '/settings/ebooks');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2.panel-title', 'Ebooks');
        self::assertSelectorExists('input[name="outputDirectory"]');
        self::assertSelectorExists('input[name="filenameTemplate"]');
        // The template note distinguishes the ebook filename from the audiobook
        // album folder template and links to the Audiobooks tab.
        self::assertSelectorExists('a[href="/settings/audiobooks"]');
    }

    public function testPostPersistsDeliveryAndPreservesChannelConfig(): void
    {
        // This page owns only the two delivery keys: everything else in the
        // shared blob — mirrors, bypass, fast downloads, priority — and the
        // row's master enabled column must survive its save untouched.
        $this->seedDirectDownloadConfig([
            'indexerPriority' => [
                ['id' => 'libgen', 'enabled' => true],
                ['id' => 'annas_archive', 'enabled' => false],
            ],
            'mirrors'             => ['annas_archive' => ['https://m.test']],
            'fastDownloadEnabled' => true,
            'bypassMode'          => 'none',
        ], enabled: false);

        $this->client->loginUser($this->loadAdmin());
        $token = $this->fetchCsrfToken('/settings/ebooks');

        $this->client->request('POST', '/settings/ebooks', [
            '_token'           => $token,
            'outputDirectory'  => '/library/ebooks',
            'filenameTemplate' => '{Author}/{Title} ({Year})',
        ]);

        self::assertResponseRedirects('/settings/ebooks');

        $this->em->clear();
        $config = $this->integrations->getDirectDownloadConfig();
        $integration = $this->integrations->findByKind(Integration::KIND_DIRECT_DOWNLOAD);
        self::assertNotNull($integration);

        self::assertSame('/library/ebooks', $config->outputDirectory);
        self::assertSame('{Author}/{Title} ({Year})', $config->filenameTemplate);

        // Channel config and the master enabled column survive untouched.
        self::assertFalse($integration->isEnabled());
        self::assertSame(['https://m.test'], $config->mirrorsFor('annas_archive')->toArray());
        self::assertTrue($config->fastDownloadEnabled);
        self::assertSame(DirectDownloadConfig::BYPASS_NONE, $config->bypassMode);
        self::assertSame(
            [
                ['id' => 'libgen', 'enabled' => true],
                ['id' => 'annas_archive', 'enabled' => false],
            ],
            $config->indexerPriority,
        );
    }

    public function testEmptyFieldsFallBackToDefaults(): void
    {
        $this->seedDirectDownloadConfig([
            'outputDirectory'  => '/library/old',
            'filenameTemplate' => '{Title}',
        ], enabled: true);

        $this->client->loginUser($this->loadAdmin());
        $token = $this->fetchCsrfToken('/settings/ebooks');

        $this->client->request('POST', '/settings/ebooks', [
            '_token'           => $token,
            'outputDirectory'  => '',
            'filenameTemplate' => '',
        ]);

        self::assertResponseRedirects('/settings/ebooks');

        $this->em->clear();
        $config = $this->integrations->getDirectDownloadConfig();
        self::assertSame(DirectDownloadConfig::DEFAULT_OUTPUT_DIRECTORY, $config->outputDirectory);
        self::assertSame(DirectDownloadConfig::DEFAULT_FILENAME_TEMPLATE, $config->filenameTemplate);
        // Clearing delivery must not flip the master switch.
        $integration = $this->integrations->findByKind(Integration::KIND_DIRECT_DOWNLOAD);
        self::assertNotNull($integration);
        self::assertTrue($integration->isEnabled());
    }

    public function testRejectsInvalidCsrfToken(): void
    {
        $this->client->loginUser($this->loadAdmin());
        $this->client->request('POST', '/settings/ebooks', [
            '_token'          => 'nope',
            'outputDirectory' => '/library/ebooks',
        ]);
        self::assertResponseRedirects('/settings/ebooks');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Invalid CSRF token');

        // Nothing was written: no direct_download row appears.
        $this->em->clear();
        self::assertNull($this->integrations->findByKind(Integration::KIND_DIRECT_DOWNLOAD));
    }

    public function testRequiresAdminRole(): void
    {
        $this->client->request('GET', '/settings/ebooks');
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
        $config = DirectDownloadConfig::fromArray(
            $raw,
            self::getContainer()->get(\App\Mirror\MirrorListNormalizer::class),
        );
        $this->integrations->saveDirectDownloadConfig($config, $enabled, $this->em);
        $this->em->flush();
        $this->em->clear();
    }

    private function seedAdmin(): void
    {
        $hasher = self::getContainer()->get('security.user_password_hasher');
        $user = new User('admin-ebooks');
        $user->setRoles([User::ROLE_ADMIN]);
        $user->setPassword($hasher->hashPassword($user, 'doesnt-matter'));
        $this->em->persist($user);
        $this->em->flush();
    }

    private function loadAdmin(): User
    {
        $user = self::getContainer()->get('doctrine')
            ->getRepository(User::class)
            ->findOneBy(['username' => 'admin-ebooks']);
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
