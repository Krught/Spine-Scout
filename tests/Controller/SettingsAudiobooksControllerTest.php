<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Download\Torrent\TorrentClientConfig;
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

    public function testGetRendersAudiobookDeliverySections(): void
    {
        $this->client->loginUser($this->loadAdmin());
        $this->client->request('GET', '/settings/audiobooks');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2.panel-title', 'Audiobooks');
        self::assertSelectorExists('input[name="audio_output_directory"]');
        self::assertSelectorExists('input[name="use_ebook_library_dir"]');
        self::assertSelectorExists('input[name="staging_subdir"]');
        self::assertSelectorExists('input[name="torrent_filename_template"]');
        // The audio-tags flag renders inside the main form, checked by default.
        self::assertSelectorExists('input[name="write_audio_tags"][checked]');
        // The Grimmory sidecar toggle is back on this page, checked by default.
        self::assertSelectorExists('input[name="write_grimmory_sidecars"][checked]');
        // The native-API sidecar-import section renders inside the main form.
        self::assertSelectorExists('input[name="native_sidecar_import"]');
        self::assertSelectorExists('input[name="native_username"]');
        self::assertSelectorExists('input[name="native_password"][type="password"]');
        // The rewrite-all button posts to its own standalone form at the restored path.
        self::assertSelectorExists('form[action="/settings/audiobooks/rewrite-sidecars"] button');
        self::assertSelectorNotExists('form[action="/settings/grimmory/rewrite-sidecars"]');
        // The torrent stack moved to Settings → Torrents; this page links there
        // and no longer renders any connection fields.
        self::assertSelectorExists('a[href="/settings/torrents"]');
        self::assertSelectorNotExists('input[name="prowlarr_base_url"]');
        self::assertSelectorNotExists('input[name="qbittorrent_base_url"]');
        self::assertSelectorNotExists('input[name="qbittorrent_category"]');
        self::assertSelectorNotExists('input[name="remove_on_complete"]');
        self::assertSelectorNotExists('input[name="reconcile_interval_hours"]');
    }

    public function testPostPersistsAudiobookDeliveryRoundTrip(): void
    {
        $this->client->loginUser($this->loadAdmin());
        $token = $this->fetchCsrfToken('/settings/audiobooks');

        $this->client->request('POST', '/settings/audiobooks', [
            '_token'                    => $token,
            'audio_output_directory'    => '/audiobooks',
            'use_ebook_library_dir'     => '0',
            'staging_subdir'            => 'staging-ab',
            'torrent_filename_template' => '{Author} - {Title} ({Year})',
        ]);

        self::assertResponseRedirects('/settings/audiobooks');

        $this->em->clear();
        $config = $this->integrations->getTorrentClientConfig();
        self::assertSame('/audiobooks', $config->audioOutputDirectory);
        self::assertFalse($config->useEbookLibraryDir);
        self::assertSame('staging-ab', $config->stagingSubdir);
        self::assertSame('{Author} - {Title} ({Year})', $config->filenameTemplate);
        // Torrent-stack keys weren't posted and keep their defaults untouched.
        self::assertTrue($config->removeOnComplete);
        self::assertSame(6, $config->reconcileIntervalHours);
    }

    public function testAbsentOutputDirectoryKeepsStoredPath(): void
    {
        $this->client->loginUser($this->loadAdmin());

        // Seed a custom output folder.
        $token = $this->fetchCsrfToken('/settings/audiobooks');
        $this->client->request('POST', '/settings/audiobooks', [
            '_token'                 => $token,
            'audio_output_directory' => '/custom/audiobooks',
            'staging_subdir'         => 'torrents',
        ]);
        self::assertResponseRedirects('/settings/audiobooks');

        // Save with "deliver into the ebook library" ticked: the folder input is
        // disabled client-side and therefore absent from the POST entirely.
        $token = $this->fetchCsrfToken('/settings/audiobooks');
        $this->client->request('POST', '/settings/audiobooks', [
            '_token'                => $token,
            'use_ebook_library_dir' => '1',
            'staging_subdir'        => 'torrents',
        ]);
        self::assertResponseRedirects('/settings/audiobooks');

        $this->em->clear();
        $config = $this->integrations->getTorrentClientConfig();
        self::assertTrue($config->useEbookLibraryDir);
        self::assertSame('/custom/audiobooks', $config->audioOutputDirectory);

        // The page now renders the folder input disabled and dimmed.
        $this->client->request('GET', '/settings/audiobooks');
        self::assertSelectorExists('input[name="audio_output_directory"][disabled]');
        self::assertSelectorExists('.field.is-disabled input[name="audio_output_directory"]');
    }

    public function testPostDoesNotTouchQbittorrentConnectionOrEnabledFlag(): void
    {
        // Seed a fully configured qbittorrent row as Settings → Torrents would.
        $row = new Integration(Integration::KIND_QBITTORRENT);
        $row->setAuthType(Integration::AUTH_BASIC);
        $row->setBaseUrl('http://qbittorrent:8080');
        $row->setCredentials(['username' => 'admin', 'password' => 'adminpass']);
        $row->setEnabled(true);
        $this->em->persist($row);
        $this->em->flush();

        $this->client->loginUser($this->loadAdmin());
        $token = $this->fetchCsrfToken('/settings/audiobooks');
        $this->client->request('POST', '/settings/audiobooks', [
            '_token'                 => $token,
            'audio_output_directory' => '/audiobooks',
        ]);
        self::assertResponseRedirects('/settings/audiobooks');

        $this->em->clear();
        $fresh = $this->integrations->findByKind(Integration::KIND_QBITTORRENT);
        self::assertNotNull($fresh);
        self::assertTrue($fresh->isEnabled());
        self::assertSame('http://qbittorrent:8080', $fresh->getBaseUrl());
        self::assertSame(Integration::AUTH_BASIC, $fresh->getAuthType());
        self::assertSame('admin', $fresh->getCredentials()['username'] ?? null);
        self::assertSame('adminpass', $fresh->getCredentials()['password'] ?? null);
    }

    public function testWriteAudioTagsCheckboxPersistsOffAndOn(): void
    {
        $this->client->loginUser($this->loadAdmin());

        // Checkbox absent from the POST (unticked) → the flag persists as false.
        $token = $this->fetchCsrfToken('/settings/audiobooks');
        $this->client->request('POST', '/settings/audiobooks', [
            '_token' => $token,
        ]);
        self::assertResponseRedirects('/settings/audiobooks');
        $this->em->clear();
        $config = $this->integrations->getTorrentClientConfig();
        self::assertFalse($config->writeAudioTags);

        // Ticked → the flag persists as true again.
        $token = $this->fetchCsrfToken('/settings/audiobooks');
        $this->client->request('POST', '/settings/audiobooks', [
            '_token'           => $token,
            'write_audio_tags' => '1',
        ]);
        self::assertResponseRedirects('/settings/audiobooks');
        $this->em->clear();
        $config = $this->integrations->getTorrentClientConfig();
        self::assertTrue($config->writeAudioTags);
    }

    public function testWriteGrimmorySidecarsCheckboxPersistsOffAndOn(): void
    {
        $this->client->loginUser($this->loadAdmin());

        // Checkbox absent from the POST (unticked) → the flag persists as false.
        $token = $this->fetchCsrfToken('/settings/audiobooks');
        $this->client->request('POST', '/settings/audiobooks', [
            '_token' => $token,
        ]);
        self::assertResponseRedirects('/settings/audiobooks');
        $this->em->clear();
        $config = $this->integrations->getTorrentClientConfig();
        self::assertFalse($config->writeGrimmorySidecars);

        // Ticked → the flag persists as true again.
        $token = $this->fetchCsrfToken('/settings/audiobooks');
        $this->client->request('POST', '/settings/audiobooks', [
            '_token'                  => $token,
            'write_grimmory_sidecars' => '1',
        ]);
        self::assertResponseRedirects('/settings/audiobooks');
        $this->em->clear();
        $config = $this->integrations->getTorrentClientConfig();
        self::assertTrue($config->writeGrimmorySidecars);
    }

    public function testNativeOptionsRoundTrip(): void
    {
        $this->client->loginUser($this->loadAdmin());

        // First save creates the grimmory row and stores the native block.
        $token = $this->fetchCsrfToken('/settings/audiobooks');
        $this->client->request('POST', '/settings/audiobooks', [
            '_token'                => $token,
            'native_sidecar_import' => '1',
            'native_username'       => 'meta-user',
            'native_password'       => 'meta-pass',
        ]);
        self::assertResponseRedirects('/settings/audiobooks');

        $this->em->clear();
        $grimmory = $this->integrations->findByKind(Integration::KIND_GRIMMORY);
        self::assertNotNull($grimmory);
        // assertEquals: jsonb storage does not preserve key order.
        self::assertEquals([
            'username'      => 'meta-user',
            'password'      => 'meta-pass',
            'sidecarImport' => true,
        ], $grimmory->getOptions()['native'] ?? null);

        // The page renders the stored username, the toggle ticked, and the
        // password field empty with the keep-current placeholder.
        $this->client->request('GET', '/settings/audiobooks');
        self::assertSelectorExists('input[name="native_sidecar_import"][checked]');
        self::assertSelectorExists('input[name="native_username"][value="meta-user"]');
        self::assertSelectorNotExists('input[name="native_password"][value]');
        self::assertSelectorExists('input[name="native_password"][placeholder*="leave blank to keep current"]');

        // Blank password keeps the stored one; username and toggle update.
        $token = $this->fetchCsrfToken('/settings/audiobooks');
        $this->client->request('POST', '/settings/audiobooks', [
            '_token'          => $token,
            'native_username' => 'meta-user-2',
            'native_password' => '',
        ]);
        self::assertResponseRedirects('/settings/audiobooks');

        $this->em->clear();
        $grimmory = $this->integrations->findByKind(Integration::KIND_GRIMMORY);
        self::assertNotNull($grimmory);
        self::assertEquals([
            'username'      => 'meta-user-2',
            'password'      => 'meta-pass',
            'sidecarImport' => false,
        ], $grimmory->getOptions()['native'] ?? null);

        // A non-blank password replaces the stored one.
        $token = $this->fetchCsrfToken('/settings/audiobooks');
        $this->client->request('POST', '/settings/audiobooks', [
            '_token'                => $token,
            'native_sidecar_import' => '1',
            'native_username'       => 'meta-user-2',
            'native_password'       => 'new-pass',
        ]);
        self::assertResponseRedirects('/settings/audiobooks');

        $this->em->clear();
        $grimmory = $this->integrations->findByKind(Integration::KIND_GRIMMORY);
        self::assertNotNull($grimmory);
        self::assertSame('new-pass', $grimmory->getOptions()['native']['password'] ?? null);
    }

    /**
     * The native block merge-saves into the grimmory row's options: every other
     * options key — and the row's connection fields, which this page never
     * edits — must survive the save untouched.
     */
    public function testPostPreservesGrimmoryRowOtherOptionsAndConnection(): void
    {
        $grimId = $this->seedGrimmoryRow();

        $this->client->loginUser($this->loadAdmin());
        $token = $this->fetchCsrfToken('/settings/audiobooks');
        $this->client->request('POST', '/settings/audiobooks', [
            '_token'                => $token,
            'native_sidecar_import' => '1',
            'native_username'       => 'meta-user',
            'native_password'       => '',
        ]);
        self::assertResponseRedirects('/settings/audiobooks');

        $this->em->clear();
        $grimmory = $this->integrations->findByKind(Integration::KIND_GRIMMORY);
        self::assertNotNull($grimmory);
        self::assertSame($grimId, $grimmory->getId());
        // Other options keys survive the merge-save.
        self::assertSame('keep-me', $grimmory->getOptions()['other'] ?? null);
        // The native block itself was updated (blank password kept the seeded one).
        self::assertSame('meta-user', $grimmory->getOptions()['native']['username'] ?? null);
        self::assertSame('seeded-pass', $grimmory->getOptions()['native']['password'] ?? null);
        self::assertTrue($grimmory->getOptions()['native']['sidecarImport'] ?? false);
        // Connection fields and the enabled flag are untouched.
        self::assertTrue($grimmory->isEnabled());
        self::assertSame('http://komga:25600', $grimmory->getBaseUrl());
        self::assertSame(Integration::AUTH_BASIC, $grimmory->getAuthType());
        self::assertSame('komga-user', $grimmory->getCredentials()['username'] ?? null);
        self::assertSame('komga-pass', $grimmory->getCredentials()['password'] ?? null);
    }

    public function testRewriteSidecarsRouteAtRestoredPath(): void
    {
        $this->client->loginUser($this->loadAdmin());

        // Valid CSRF, no downloaded audiobooks → error flash, back to this tab.
        $crawler = $this->client->request('GET', '/settings/audiobooks');
        $token = $crawler
            ->filter('form[action="/settings/audiobooks/rewrite-sidecars"] input[name="_token"]')
            ->attr('value');
        self::assertNotNull($token);
        $this->client->request('POST', '/settings/audiobooks/rewrite-sidecars', ['_token' => $token]);
        self::assertResponseRedirects('/settings/audiobooks');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'No downloaded audiobooks found');

        // Invalid CSRF also redirects to this tab.
        $this->client->request('POST', '/settings/audiobooks/rewrite-sidecars', ['_token' => 'nope']);
        self::assertResponseRedirects('/settings/audiobooks');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Invalid CSRF token');

        // The temporary Komga-tab path is gone.
        $this->client->request('POST', '/settings/grimmory/rewrite-sidecars', ['_token' => $token]);
        self::assertResponseStatusCodeSame(404);
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

    /** Seed a fully configured grimmory row as Settings → Komga would, plus a foreign options key. */
    private function seedGrimmoryRow(): ?int
    {
        // Drop the per-request memo: it caches null lookups, and reusing the
        // repository across requests would otherwise re-create existing rows.
        $this->integrations->clearSettingsCache();
        $grimmory = $this->integrations->getOrCreate(Integration::KIND_GRIMMORY);
        $grimmory->setAuthType(Integration::AUTH_BASIC);
        $grimmory->setBaseUrl('http://komga:25600');
        $grimmory->setCredentials(['username' => 'komga-user', 'password' => 'komga-pass']);
        $grimmory->setEnabled(true);
        $grimmory->setOptions([
            'native' => [
                'username'      => 'seeded-user',
                'password'      => 'seeded-pass',
                'sidecarImport' => false,
            ],
            'other' => 'keep-me',
        ]);
        if ($grimmory->getId() === null) {
            $this->em->persist($grimmory);
        }
        $this->em->flush();

        return $grimmory->getId();
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

    /** The MAIN settings form's token — it renders before the rewrite form, so first match wins. */
    private function fetchCsrfToken(string $path): string
    {
        $crawler = $this->client->request('GET', $path);
        $token = $crawler->filter('input[name="_token"]')->first()->attr('value');
        self::assertNotNull($token, "Expected CSRF token rendered at {$path}");
        return $token;
    }
}
