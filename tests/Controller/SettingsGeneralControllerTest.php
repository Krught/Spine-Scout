<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Integration;
use App\Entity\User;
use App\Integration\Hardcover\HardcoverClient;
use App\Mirror\MirrorListNormalizer;
use App\Repository\BookRepository;
use App\Repository\IntegrationRepository;
use App\Search\DirectDownload\DirectDownloadConfig;
use App\Service\CoverCache;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class SettingsGeneralControllerTest extends WebTestCase
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

    public function testGetRendersSourcePriorityListWithNothingLockedByDefault(): void
    {
        $this->client->loginUser($this->loadAdmin());
        $crawler = $this->client->request('GET', '/settings/general');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2.panel-title', 'General');
        self::assertSelectorExists('[data-controller="orderable-list"]');
        self::assertSelectorExists('input[type="hidden"][name="indexerPriority"]');

        $list = $crawler->filter('[data-controller="orderable-list"]');
        // All six sources render, in default order on a fresh install.
        self::assertSame(
            ['annas_archive', 'libgen', 'zlibrary', 'welib', 'torrent', 'mam'],
            array_column(json_decode((string) $list->attr('data-orderable-list-initial-value'), true), 'id'),
        );
        // No direct_download row yet → the master switch defaults to on → nothing locked.
        self::assertSame([], json_decode((string) $list->attr('data-orderable-list-locked-ids-value'), true));
    }

    public function testGetLocksTheFourMirrorSourcesWhileMasterSwitchOff(): void
    {
        $this->seedDirectDownloadConfig([
            'indexerPriority' => [
                ['id' => 'annas_archive', 'enabled' => true],
                ['id' => 'libgen', 'enabled' => false],
                ['id' => 'zlibrary', 'enabled' => true],
                ['id' => 'welib', 'enabled' => true],
                ['id' => 'torrent', 'enabled' => true],
            ],
        ], enabled: false);

        $this->client->loginUser($this->loadAdmin());
        $crawler = $this->client->request('GET', '/settings/general');

        self::assertResponseIsSuccessful();

        $list = $crawler->filter('[data-controller="orderable-list"]');
        // The four HTTP mirror sources are locked; the torrent and mam rows stay interactive.
        self::assertSame(
            ['annas_archive', 'libgen', 'zlibrary', 'welib'],
            json_decode((string) $list->attr('data-orderable-list-locked-ids-value'), true),
        );
        // The rows still carry the STORED ticks (not the force-disabled view), so a
        // save round-trips them unchanged. The mam row is absent from this stored
        // config and renders backfilled with the fresh-install default tick.
        self::assertSame(
            ['annas_archive' => true, 'libgen' => false, 'zlibrary' => true, 'welib' => true, 'torrent' => true, 'mam' => true],
            array_column(json_decode((string) $list->attr('data-orderable-list-initial-value'), true), 'enabled', 'id'),
        );
        // And the note links the operator to the tab that re-enables them.
        self::assertSelectorExists('a[href="/settings/direct-download"]');
    }

    public function testPostPersistsPriorityIntoDirectDownloadConfigWithoutClobberingOtherKeys(): void
    {
        $this->seedDirectDownloadConfig([
            'indexerPriority' => [
                ['id' => 'annas_archive', 'enabled' => true],
                ['id' => 'libgen', 'enabled' => true],
                ['id' => 'zlibrary', 'enabled' => true],
                ['id' => 'welib', 'enabled' => true],
                ['id' => 'torrent', 'enabled' => false],
            ],
            'mirrors'             => ['annas_archive' => ['https://m.test']],
            'fastDownloadEnabled' => true,
            'outputDirectory'     => '/custom/out',
            'filenameTemplate'    => '{Title} only',
            'bypassMode'          => DirectDownloadConfig::BYPASS_NONE,
        ], enabled: false);

        $this->client->loginUser($this->loadAdmin());
        $token = $this->fetchCsrfToken('/settings/general');

        $this->client->request('POST', '/settings/general', [
            '_token'             => $token,
            'overwrite_metadata' => '1',
            'indexerPriority'    => json_encode([
                ['id' => 'torrent', 'enabled' => true],
                ['id' => 'zlibrary', 'enabled' => false],
                ['id' => 'bogus', 'enabled' => true], // unknown id — must be dropped
                ['id' => 'annas_archive', 'enabled' => true],
                ['id' => 'libgen', 'enabled' => true],
                // welib and mam omitted — must be backfilled, disabled
            ]),
        ]);

        self::assertResponseRedirects('/settings/general');

        $this->em->clear();
        $config = $this->integrations->getDirectDownloadConfig();

        // The posted order + ticks landed (unknown dropped, missing backfilled off).
        self::assertSame(
            [
                ['id' => 'torrent', 'enabled' => true],
                ['id' => 'zlibrary', 'enabled' => false],
                ['id' => 'annas_archive', 'enabled' => true],
                ['id' => 'libgen', 'enabled' => true],
                ['id' => 'welib', 'enabled' => false],
                ['id' => 'mam', 'enabled' => false],
            ],
            $config->indexerPriority,
        );

        // Merge-save: every other config key survives untouched…
        self::assertSame(['https://m.test'], $config->mirrorsFor('annas_archive')->toArray());
        self::assertTrue($config->fastDownloadEnabled);
        self::assertSame('/custom/out', $config->outputDirectory);
        self::assertSame('{Title} only', $config->filenameTemplate);
        self::assertSame(DirectDownloadConfig::BYPASS_NONE, $config->bypassMode);

        // …as does the master switch (the row's enabled column stays off).
        $row = $this->integrations->findByKind(Integration::KIND_DIRECT_DOWNLOAD);
        self::assertNotNull($row);
        self::assertFalse($row->isEnabled());

        // The page's own settings saved too.
        $app = $this->integrations->findByKind(Integration::KIND_APP);
        self::assertNotNull($app);
        self::assertTrue($app->isOverwriteMetadataEnabled());
    }

    public function testPostRoundTripsAllSixSourceIdsInThePostedOrder(): void
    {
        $this->client->loginUser($this->loadAdmin());
        $token = $this->fetchCsrfToken('/settings/general');

        // Every known source posted, scrambled, with mixed ticks — nothing to
        // drop, nothing to backfill.
        $posted = [
            ['id' => 'mam', 'enabled' => true],
            ['id' => 'torrent', 'enabled' => false],
            ['id' => 'welib', 'enabled' => true],
            ['id' => 'annas_archive', 'enabled' => false],
            ['id' => 'zlibrary', 'enabled' => true],
            ['id' => 'libgen', 'enabled' => true],
        ];
        $this->client->request('POST', '/settings/general', [
            '_token'          => $token,
            'indexerPriority' => json_encode($posted),
        ]);

        self::assertResponseRedirects('/settings/general');

        $this->em->clear();
        self::assertSame($posted, $this->integrations->getDirectDownloadConfig()->indexerPriority);
    }

    public function testPostOnFreshInstallCreatesTheRowWithMasterSwitchOn(): void
    {
        // No direct_download row yet: absent behaves as master-on, so the row this
        // save creates must be enabled — saving General must never flip the
        // effective master switch off as a side effect.
        $this->client->loginUser($this->loadAdmin());
        $token = $this->fetchCsrfToken('/settings/general');

        $this->client->request('POST', '/settings/general', [
            '_token'          => $token,
            'indexerPriority' => json_encode([['id' => 'libgen', 'enabled' => true]]),
        ]);

        self::assertResponseRedirects('/settings/general');

        $this->em->clear();
        $row = $this->integrations->findByKind(Integration::KIND_DIRECT_DOWNLOAD);
        self::assertNotNull($row);
        self::assertTrue($row->isEnabled());
        self::assertTrue($this->integrations->getDirectDownloadConfig()->isIndexerEnabled('libgen'));
    }

    public function testAutomaticFulfillmentCheckboxRendersCurrentState(): void
    {
        $this->client->loginUser($this->loadAdmin());

        // Missing row/option behaves as enabled, so a fresh install renders checked.
        $this->client->request('GET', '/settings/general');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="automatic_fulfillment"][checked]');

        $this->integrations->setAutomaticFulfillmentEnabled(false);
        $this->em->clear();

        $this->client->request('GET', '/settings/general');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="automatic_fulfillment"]:not([checked])');
        // The help text points the operator at the manual queue the flag gates.
        self::assertSelectorExists('a[href="/requests"]');
    }

    public function testPostPersistsAutomaticFulfillmentRoundTrip(): void
    {
        $this->client->loginUser($this->loadAdmin());

        // Checked box switches the pipeline on…
        $this->client->request('POST', '/settings/general', [
            '_token'                => $this->fetchCsrfToken('/settings/general'),
            'automatic_fulfillment' => '1',
        ]);
        self::assertResponseRedirects('/settings/general');
        $this->em->clear();
        self::assertTrue($this->integrations->isAutomaticFulfillmentEnabled());

        // …and an unchecked box (the field is simply absent from the POST)
        // switches it off.
        $this->client->request('POST', '/settings/general', [
            '_token' => $this->fetchCsrfToken('/settings/general'),
        ]);
        self::assertResponseRedirects('/settings/general');
        $this->em->clear();
        self::assertFalse($this->integrations->isAutomaticFulfillmentEnabled());
    }

    public function testRejectsInvalidCsrfToken(): void
    {
        $this->client->loginUser($this->loadAdmin());
        $this->client->request('POST', '/settings/general', [
            '_token' => 'nope',
        ]);
        self::assertResponseRedirects('/settings/general');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Invalid CSRF token');
    }

    public function testClearCoverCacheButtonRenders(): void
    {
        $this->client->loginUser($this->loadAdmin());
        $this->client->request('GET', '/settings/general');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[action$="/settings/general/clear-cover-cache"]');
    }

    public function testClearCoverCacheWipesCacheDirAndFlashesCount(): void
    {
        // The real CoverCache points at the bind-mounted book-covers dir; swap in one
        // rooted in a throwaway temp dir so the test never touches genuine cached covers.
        $cacheDir = sys_get_temp_dir() . '/spinescout_covers_' . bin2hex(random_bytes(6));
        @mkdir($cacheDir . '/ab', 0775, true);
        file_put_contents($cacheDir . '/ab/abcd.webp', 'IMG');
        file_put_contents($cacheDir . '/ab/abcd.meta', '{"kind":"remote","url":"http://img.test/c.jpg"}');

        $this->client->disableReboot();
        $container = self::getContainer();
        $container->set(CoverCache::class, new CoverCache(
            $cacheDir,
            $container->get(HttpClientInterface::class),
            $this->integrations,
            $container->get(UrlGeneratorInterface::class),
            $container->get(BookRepository::class),
            $container->get(HardcoverClient::class),
        ));

        $this->client->loginUser($this->loadAdmin());
        $crawler = $this->client->request('GET', '/settings/general');
        $token = $crawler
            ->filter('form[action$="/settings/general/clear-cover-cache"] input[name="_token"]')
            ->attr('value');
        self::assertNotNull($token);

        $this->client->request('POST', '/settings/general/clear-cover-cache', ['_token' => $token]);

        self::assertResponseRedirects('/settings/general');
        self::assertFileDoesNotExist($cacheDir . '/ab/abcd.webp');
        self::assertFileDoesNotExist($cacheDir . '/ab/abcd.meta');

        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', '1 cached image removed');

        @rmdir($cacheDir . '/ab');
        @rmdir($cacheDir);
    }

    public function testClearCoverCacheRejectsInvalidCsrfToken(): void
    {
        $this->client->loginUser($this->loadAdmin());
        $this->client->request('POST', '/settings/general/clear-cover-cache', ['_token' => 'nope']);

        self::assertResponseRedirects('/settings/general');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Invalid CSRF token');
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
            self::getContainer()->get(MirrorListNormalizer::class),
        );
        $this->integrations->saveDirectDownloadConfig($config, $enabled, $this->em);
        $this->em->flush();
        $this->em->clear();
    }

    private function seedAdmin(): void
    {
        $hasher = self::getContainer()->get('security.user_password_hasher');
        $user = new User('admin-gen');
        $user->setRoles([User::ROLE_ADMIN]);
        $user->setPassword($hasher->hashPassword($user, 'doesnt-matter'));
        $this->em->persist($user);
        $this->em->flush();
    }

    private function loadAdmin(): User
    {
        $user = self::getContainer()->get('doctrine')
            ->getRepository(User::class)
            ->findOneBy(['username' => 'admin-gen']);
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
