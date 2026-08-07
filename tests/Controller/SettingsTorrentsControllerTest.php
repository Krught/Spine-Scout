<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Integration;
use App\Entity\User;
use App\Repository\IntegrationRepository;
use App\Search\Torrent\ProwlarrConfig;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SettingsTorrentsControllerTest extends WebTestCase
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
        $this->client->request('GET', '/settings/torrents');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2.panel-title', 'Torrents');
        self::assertSelectorExists('input[name="prowlarr_base_url"]');
        self::assertSelectorExists('input[name="prowlarr_api_key"]');
        self::assertSelectorExists('input[name="prowlarr_categories"]');
        self::assertSelectorExists('input[name="prowlarr_book_categories"]');
        self::assertSelectorExists('input[name="qbittorrent_base_url"]');
        self::assertSelectorExists('input[name="qbittorrent_auth_method"][value="basic"][checked]');
        self::assertSelectorExists('input[name="qbittorrent_auth_method"][value="api_key"]');
        self::assertSelectorExists('input[name="qbittorrent_api_key"][type="password"]');
        self::assertSelectorExists('input[name="qbittorrent_category"]');
        self::assertSelectorExists('input[name="remove_on_complete"]');
        self::assertSelectorExists('input[name="reconcile_interval_hours"]');
        // Sections are labelled "Indexers" and "Download Client".
        self::assertSelectorTextContains('.settings-fieldset legend', 'Indexers');
        self::assertSelectorTextContains('body', 'Download Client');
        // Both sections expose a connection-test button.
        self::assertSelectorExists('[data-connection-test-url-value$="/test/prowlarr"]');
        self::assertSelectorExists('[data-connection-test-url-value$="/test/qbittorrent"]');
        // Audiobook delivery/metadata fields live on Settings → Audiobooks now.
        self::assertSelectorNotExists('input[name="audio_output_directory"]');
        self::assertSelectorNotExists('input[name="write_grimmory_sidecars"]');
    }

    public function testConnectionTestEndpointReportsUnconfigured(): void
    {
        $this->client->loginUser($this->loadAdmin());
        // The test buttons carry their own CSRF id (settings_torrents_test); read it off the page.
        $crawler = $this->client->request('GET', '/settings/torrents');
        $testToken = $crawler->filter('[data-connection-test-token-value]')->first()
            ->attr('data-connection-test-token-value');

        $this->client->request('POST', '/settings/torrents/test/prowlarr', ['_token' => $testToken]);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertFalse($data['ok']);
        self::assertStringContainsString('not set', $data['message']);
    }

    public function testConnectionTestRejectsBadCsrf(): void
    {
        $this->client->loginUser($this->loadAdmin());
        $this->client->request('POST', '/settings/torrents/test/qbittorrent', ['_token' => 'nope']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testPostPersistsConfigRoundTrip(): void
    {
        $this->client->loginUser($this->loadAdmin());
        $token = $this->fetchCsrfToken('/settings/torrents');

        $this->client->request('POST', '/settings/torrents', [
            '_token'                   => $token,
            'prowlarr_enabled'         => '1',
            'prowlarr_base_url'        => 'http://prowlarr:9696/',
            'prowlarr_api_key'         => 'secret-key',
            'prowlarr_categories'      => '3030, 3000',
            'prowlarr_book_categories' => '7000, 7010',
            'prowlarr_search_method'   => 'filtered',
            'prowlarr_min_seeders'     => '5',
            'prowlarr_max_size_gb'     => '10',
            'prowlarr_weight_match'    => '0.6',
            'prowlarr_weight_seeders'  => '0.2',
            'prowlarr_weight_size'     => '0.1',
            'prowlarr_weight_format'   => '0.1',
            'qbittorrent_enabled'      => '1',
            'qbittorrent_base_url'     => 'http://qbittorrent:8080',
            'qbittorrent_username'     => 'admin',
            'qbittorrent_password'     => 'adminpass',
            'qbittorrent_category'     => 'audiobooks',
            'remove_on_complete'       => '1',
            'reconcile_interval_hours' => '12',
        ]);

        self::assertResponseRedirects('/settings/torrents');

        $this->em->clear();
        $prowlarr = $this->integrations->getProwlarrConfig();
        self::assertSame([3030, 3000], $prowlarr->categories);
        self::assertSame([7000, 7010], $prowlarr->bookCategories);
        self::assertSame('filtered', $prowlarr->searchMethod);
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
        self::assertTrue($client->removeOnComplete);
        self::assertSame(12, $client->reconcileIntervalHours);
        // Audiobook delivery keys weren't posted and keep their defaults untouched.
        self::assertSame('/var/www/html/audiobooks', $client->audioOutputDirectory);
        self::assertTrue($client->writeGrimmorySidecars);

        $qbitRow = $this->integrations->findByKind(Integration::KIND_QBITTORRENT);
        self::assertNotNull($qbitRow);
        self::assertSame('admin', $qbitRow->getCredentials()['username'] ?? null);
        self::assertSame(Integration::AUTH_BASIC, $qbitRow->getAuthType());
    }

    public function testEmptyCategoryInputsFallBackToDefaults(): void
    {
        $this->client->loginUser($this->loadAdmin());
        $token = $this->fetchCsrfToken('/settings/torrents');

        $this->client->request('POST', '/settings/torrents', [
            '_token'                   => $token,
            'prowlarr_base_url'        => 'http://prowlarr:9696',
            'prowlarr_categories'      => '',
            'prowlarr_book_categories' => '',
        ]);

        self::assertResponseRedirects('/settings/torrents');
        $this->em->clear();
        $config = $this->integrations->getProwlarrConfig();
        self::assertSame(ProwlarrConfig::DEFAULT_CATEGORIES, $config->categories);
        self::assertSame(ProwlarrConfig::DEFAULT_BOOK_CATEGORIES, $config->bookCategories);
    }

    public function testReconcileEndpointQueuesWithValidCsrf(): void
    {
        $this->client->loginUser($this->loadAdmin());
        // The reconcile form carries its own CSRF id (torrents_reconcile); read it off the page.
        $crawler = $this->client->request('GET', '/settings/torrents');
        $token = $crawler->filter('form[action$="/torrents/reconcile"] input[name="_token"]')->attr('value');
        self::assertNotNull($token);

        $this->client->request('POST', '/settings/torrents/reconcile', ['_token' => $token]);
        self::assertResponseRedirects('/settings/torrents');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Torrent reconcile queued');
    }

    public function testReconcileEndpointRejectsBadCsrf(): void
    {
        $this->client->loginUser($this->loadAdmin());
        $this->client->request('POST', '/settings/torrents/reconcile', ['_token' => 'nope']);
        self::assertResponseRedirects('/settings/torrents');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Invalid CSRF token');
    }

    public function testCrossPageSavesDoNotClobberEachOther(): void
    {
        $this->client->loginUser($this->loadAdmin());

        // Save the audiobooks page with non-default values (write flags unticked).
        $token = $this->fetchCsrfToken('/settings/audiobooks');
        $this->client->request('POST', '/settings/audiobooks', [
            '_token'                 => $token,
            'audio_output_directory' => '/custom-audio',
        ]);
        self::assertResponseRedirects('/settings/audiobooks');

        // Save the torrents page — the audiobooks-page fields must survive.
        $token = $this->fetchCsrfToken('/settings/torrents');
        $this->client->request('POST', '/settings/torrents', [
            '_token'                   => $token,
            'qbittorrent_base_url'     => 'http://qbittorrent:8080',
            'qbittorrent_category'     => 'custom-cat',
            'reconcile_interval_hours' => '12',
            // remove_on_complete unticked → false
        ]);
        self::assertResponseRedirects('/settings/torrents');

        $this->em->clear();
        $config = $this->integrations->getTorrentClientConfig();
        self::assertSame('/custom-audio', $config->audioOutputDirectory);
        // Both write flags are audiobooks-page checkboxes; the earlier save left
        // them unticked (false) and the torrents save must not resurrect them.
        self::assertFalse($config->writeGrimmorySidecars);
        self::assertFalse($config->writeAudioTags);
        self::assertSame('custom-cat', $config->category);
        self::assertFalse($config->removeOnComplete);
        self::assertSame(12, $config->reconcileIntervalHours);

        // Save the audiobooks page again — the torrents-page fields must survive.
        $token = $this->fetchCsrfToken('/settings/audiobooks');
        $this->client->request('POST', '/settings/audiobooks', [
            '_token'                  => $token,
            'audio_output_directory'  => '/custom-audio-2',
            'write_grimmory_sidecars' => '1',
        ]);
        self::assertResponseRedirects('/settings/audiobooks');

        $this->em->clear();
        $config = $this->integrations->getTorrentClientConfig();
        self::assertSame('/custom-audio-2', $config->audioOutputDirectory);
        self::assertTrue($config->writeGrimmorySidecars);
        self::assertSame('custom-cat', $config->category);
        self::assertFalse($config->removeOnComplete);
        self::assertSame(12, $config->reconcileIntervalHours);
    }

    public function testQbittorrentApiKeyAuthMethodPersists(): void
    {
        $this->client->loginUser($this->loadAdmin());
        $token = $this->fetchCsrfToken('/settings/torrents');

        $this->client->request('POST', '/settings/torrents', [
            '_token'                  => $token,
            'qbittorrent_enabled'     => '1',
            'qbittorrent_base_url'    => 'http://qbittorrent:8080',
            'qbittorrent_auth_method' => 'api_key',
            'qbittorrent_api_key'     => 'qbt_abcdefghijklmnopqrstuvwxyz00',
        ]);

        self::assertResponseRedirects('/settings/torrents');
        $this->em->clear();
        $row = $this->integrations->findByKind(Integration::KIND_QBITTORRENT);
        self::assertNotNull($row);
        self::assertSame(Integration::AUTH_API_KEY, $row->getAuthType());
        self::assertSame('qbt_abcdefghijklmnopqrstuvwxyz00', $row->getCredentials()['api_key'] ?? null);

        // The saved page preselects the API-key radio.
        $this->client->followRedirect();
        self::assertSelectorExists('input[name="qbittorrent_auth_method"][value="api_key"][checked]');
    }

    public function testBlankQbittorrentApiKeyKeepsExisting(): void
    {
        $row = new Integration(Integration::KIND_QBITTORRENT);
        $row->setAuthType(Integration::AUTH_API_KEY);
        $row->setBaseUrl('http://qbittorrent:8080');
        $row->setCredentials(['api_key' => 'qbt_original']);
        $row->setEnabled(true);
        $this->em->persist($row);
        $this->em->flush();

        $this->client->loginUser($this->loadAdmin());
        $token = $this->fetchCsrfToken('/settings/torrents');

        $this->client->request('POST', '/settings/torrents', [
            '_token'                  => $token,
            'qbittorrent_enabled'     => '1',
            'qbittorrent_base_url'    => 'http://qbittorrent:8080',
            'qbittorrent_auth_method' => 'api_key',
            'qbittorrent_api_key'     => '', // blank — keep existing
        ]);

        self::assertResponseRedirects('/settings/torrents');
        $this->em->clear();
        $fresh = $this->integrations->findByKind(Integration::KIND_QBITTORRENT);
        self::assertSame('qbt_original', $fresh?->getCredentials()['api_key'] ?? null);
        self::assertSame(Integration::AUTH_API_KEY, $fresh?->getAuthType());
    }

    public function testUnknownQbittorrentAuthMethodFallsBackToBasic(): void
    {
        $this->client->loginUser($this->loadAdmin());
        $token = $this->fetchCsrfToken('/settings/torrents');

        $this->client->request('POST', '/settings/torrents', [
            '_token'                  => $token,
            'qbittorrent_base_url'    => 'http://qbittorrent:8080',
            'qbittorrent_auth_method' => 'something-else',
            'qbittorrent_username'    => 'admin',
            'qbittorrent_password'    => 'adminpass',
        ]);

        self::assertResponseRedirects('/settings/torrents');
        $this->em->clear();
        $row = $this->integrations->findByKind(Integration::KIND_QBITTORRENT);
        self::assertSame(Integration::AUTH_BASIC, $row?->getAuthType());
        self::assertSame('admin', $row?->getCredentials()['username'] ?? null);
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
        $token = $this->fetchCsrfToken('/settings/torrents');

        $this->client->request('POST', '/settings/torrents', [
            '_token'            => $token,
            'prowlarr_enabled'  => '1',
            'prowlarr_base_url' => 'http://prowlarr:9696',
            'prowlarr_api_key'  => '', // blank — keep existing
        ]);

        self::assertResponseRedirects('/settings/torrents');
        $this->em->clear();
        $fresh = $this->integrations->findByKind(Integration::KIND_PROWLARR);
        self::assertSame('original-token', $fresh?->getCredentials()['token'] ?? null);
    }

    public function testRejectsInvalidCsrfToken(): void
    {
        $this->client->loginUser($this->loadAdmin());
        $this->client->request('POST', '/settings/torrents', ['_token' => 'nope']);
        self::assertResponseRedirects('/settings/torrents');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Invalid CSRF token');
    }

    public function testRequiresAdminRole(): void
    {
        $this->client->request('GET', '/settings/torrents');
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    private function seedAdmin(): void
    {
        $hasher = self::getContainer()->get('security.user_password_hasher');
        $user = new User('admin-tor');
        $user->setRoles([User::ROLE_ADMIN]);
        $user->setPassword($hasher->hashPassword($user, 'doesnt-matter'));
        $this->em->persist($user);
        $this->em->flush();
    }

    private function loadAdmin(): User
    {
        $user = self::getContainer()->get('doctrine')
            ->getRepository(User::class)
            ->findOneBy(['username' => 'admin-tor']);
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
