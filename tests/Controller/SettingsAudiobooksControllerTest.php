<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Integration;
use App\Entity\User;
use App\Repository\IntegrationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SettingsAudiobooksControllerTest extends WebTestCase
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

    public function testGetRendersProwlarrAndQbittorrentSections(): void
    {
        $this->client->loginUser($this->loadAdmin());
        $this->client->request('GET', '/settings/audiobooks');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2.panel-title', 'Torrents');
        self::assertSelectorExists('input[name="prowlarr_base_url"]');
        self::assertSelectorExists('input[name="prowlarr_api_key"]');
        self::assertSelectorExists('input[name="qbittorrent_base_url"]');
        self::assertSelectorExists('input[name="use_ebook_library_dir"]');
        // Sections are labelled "Indexers" and "Download Client".
        self::assertSelectorTextContains('.settings-fieldset legend', 'Indexers');
        self::assertSelectorTextContains('body', 'Download Client');
        // Both sections expose a connection-test button.
        self::assertSelectorExists('[data-connection-test-url-value$="/test/prowlarr"]');
        self::assertSelectorExists('[data-connection-test-url-value$="/test/qbittorrent"]');
    }

    public function testConnectionTestEndpointReportsUnconfigured(): void
    {
        $this->client->loginUser($this->loadAdmin());
        // The test buttons carry their own CSRF id (settings_audiobooks_test); read it off the page.
        $crawler = $this->client->request('GET', '/settings/audiobooks');
        $testToken = $crawler->filter('[data-connection-test-token-value]')->first()
            ->attr('data-connection-test-token-value');

        $this->client->request('POST', '/settings/audiobooks/test/prowlarr', ['_token' => $testToken]);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertFalse($data['ok']);
        self::assertStringContainsString('not set', $data['message']);
    }

    public function testConnectionTestRejectsBadCsrf(): void
    {
        $this->client->loginUser($this->loadAdmin());
        $this->client->request('POST', '/settings/audiobooks/test/qbittorrent', ['_token' => 'nope']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testPostPersistsConfigRoundTrip(): void
    {
        $this->client->loginUser($this->loadAdmin());
        $token = $this->fetchCsrfToken('/settings/audiobooks');

        $this->client->request('POST', '/settings/audiobooks', [
            '_token'                     => $token,
            'prowlarr_enabled'           => '1',
            'prowlarr_base_url'          => 'http://prowlarr:9696/',
            'prowlarr_api_key'           => 'secret-key',
            'prowlarr_categories'        => '3030, 3000',
            'prowlarr_min_seeders'       => '5',
            'prowlarr_max_size_gb'       => '10',
            'prowlarr_weight_match'      => '0.6',
            'prowlarr_weight_seeders'    => '0.2',
            'prowlarr_weight_size'       => '0.1',
            'prowlarr_weight_format'     => '0.1',
            'qbittorrent_enabled'        => '1',
            'qbittorrent_base_url'       => 'http://qbittorrent:8080',
            'qbittorrent_username'       => 'admin',
            'qbittorrent_password'       => 'adminpass',
            'qbittorrent_category'       => 'audiobooks',
            'audio_output_directory'     => '/audiobooks',
            'use_ebook_library_dir'      => '0',
            'staging_subdir'             => 'torrents',
            'torrent_filename_template'  => '{Author} - {Title}',
            'remove_on_complete'         => '1',
        ]);

        self::assertResponseRedirects('/settings/audiobooks');

        $this->em->clear();
        $prowlarr = $this->integrations->getProwlarrConfig();
        self::assertSame([3030, 3000], $prowlarr->categories);
        self::assertSame(5, $prowlarr->minSeeders);
        self::assertSame((int) round(10 * 1024 * 1024 * 1024), $prowlarr->maxSizeBytes);
        self::assertSame(0.6, $prowlarr->weights['match']);

        $prowlarrRow = $this->integrations->findByKind(Integration::KIND_PROWLARR);
        self::assertNotNull($prowlarrRow);
        self::assertTrue($prowlarrRow->isEnabled());
        self::assertSame('http://prowlarr:9696', $prowlarrRow->getBaseUrl());
        self::assertSame('secret-key', $prowlarrRow->getCredentials()['token'] ?? null);

        $client = $this->integrations->getTorrentClientConfig();
        self::assertSame('audiobooks', $client->category);
        self::assertSame('/audiobooks', $client->audioOutputDirectory);
        self::assertFalse($client->useEbookLibraryDir);
        self::assertTrue($client->removeOnComplete);

        $qbitRow = $this->integrations->findByKind(Integration::KIND_QBITTORRENT);
        self::assertNotNull($qbitRow);
        self::assertSame('admin', $qbitRow->getCredentials()['username'] ?? null);
    }

    public function testBlankSecretKeepsExistingCredential(): void
    {
        // Seed an existing Prowlarr row with a stored token.
        $row = new Integration(Integration::KIND_PROWLARR);
        $row->setAuthType(Integration::AUTH_API_KEY);
        $row->setBaseUrl('http://prowlarr:9696');
        $row->setCredentials(['token' => 'original-token']);
        $row->setEnabled(true);
        $this->em->persist($row);
        $this->em->flush();

        $this->client->loginUser($this->loadAdmin());
        $token = $this->fetchCsrfToken('/settings/audiobooks');

        $this->client->request('POST', '/settings/audiobooks', [
            '_token'             => $token,
            'prowlarr_enabled'   => '1',
            'prowlarr_base_url'  => 'http://prowlarr:9696',
            'prowlarr_api_key'   => '', // blank — keep existing
        ]);

        self::assertResponseRedirects('/settings/audiobooks');
        $this->em->clear();
        $fresh = $this->integrations->findByKind(Integration::KIND_PROWLARR);
        self::assertSame('original-token', $fresh?->getCredentials()['token'] ?? null);
    }

    public function testRejectsInvalidCsrfToken(): void
    {
        $this->client->loginUser($this->loadAdmin());
        $this->client->request('POST', '/settings/audiobooks', ['_token' => 'nope']);
        self::assertResponseRedirects('/settings/audiobooks');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Invalid CSRF token');
    }

    public function testRequiresAdminRole(): void
    {
        $this->client->request('GET', '/settings/audiobooks');
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    private function seedAdmin(): void
    {
        $hasher = self::getContainer()->get('security.user_password_hasher');
        $user = new User('admin-ab');
        $user->setRoles([User::ROLE_ADMIN]);
        $user->setPassword($hasher->hashPassword($user, 'doesnt-matter'));
        $this->em->persist($user);
        $this->em->flush();
    }

    private function loadAdmin(): User
    {
        $user = self::getContainer()->get('doctrine')
            ->getRepository(User::class)
            ->findOneBy(['username' => 'admin-ab']);
        self::assertNotNull($user);
        return $user;
    }

    private function fetchCsrfToken(string $path): string
    {
        $crawler = $this->client->request('GET', $path);
        $token = $crawler->filter('input[name="_token"]')->attr('value');
        self::assertNotNull($token, "Expected CSRF token rendered at {$path}");
        return $token;
    }
}
