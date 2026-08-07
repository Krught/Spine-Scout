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

final class SettingsGrimmoryControllerTest extends WebTestCase
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

    public function testGetRendersConnectionFormWithoutSidecarSections(): void
    {
        $this->client->loginUser($this->loadAdmin());
        $this->client->request('GET', '/settings/grimmory');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2.panel-title', 'Komga');
        self::assertSelectorExists('input[name="grimmory_integration[baseUrl]"]');
        self::assertSelectorExists('input[name="grimmory_integration[username]"]');
        self::assertSelectorExists('input[name="grimmory_integration[password]"]');
        self::assertSelectorExists('input[name="grimmory_integration[syncIntervalMinutes]"]');
        // The audiobook-sidecar sections moved to Settings → Audiobooks and no
        // longer render here — neither the toggle, the native-API account, nor
        // the rewrite-all button and its standalone form.
        self::assertSelectorNotExists('input[name="grimmory_integration[writeSidecars]"]');
        self::assertSelectorNotExists('input[name="grimmory_integration[nativeSidecarImport]"]');
        self::assertSelectorNotExists('input[name="grimmory_integration[nativeUsername]"]');
        self::assertSelectorNotExists('input[name="grimmory_integration[nativePassword]"]');
        self::assertSelectorNotExists('form#rewrite-sidecars-form');
        self::assertSelectorNotExists('button[form="rewrite-sidecars-form"]');
        self::assertSelectorNotExists('form[action="/settings/grimmory/rewrite-sidecars"]');
    }

    public function testSubmitSavesConnection(): void
    {
        $this->client->loginUser($this->loadAdmin());

        $crawler = $this->client->request('GET', '/settings/grimmory');
        $form = $crawler->selectButton('Save settings')->form();
        $form['grimmory_integration[baseUrl]'] = 'http://komga:25600';
        $form['grimmory_integration[username]'] = 'komga-user';
        $form['grimmory_integration[password]'] = 'komga-pass';
        $form['grimmory_integration[syncIntervalMinutes]'] = '30';
        $this->client->submit($form);
        self::assertResponseRedirects('/settings/grimmory');

        $this->em->clear();
        $integration = $this->integrations->findByKind(Integration::KIND_GRIMMORY);
        self::assertNotNull($integration);
        self::assertTrue($integration->isEnabled());
        self::assertSame('http://komga:25600', $integration->getBaseUrl());
        self::assertSame(Integration::AUTH_BASIC, $integration->getAuthType());
        self::assertSame(30, $integration->getSyncIntervalMinutes());
        self::assertSame('komga-user', $integration->getCredentials()['username'] ?? null);
        self::assertSame('komga-pass', $integration->getCredentials()['password'] ?? null);
    }

    /**
     * The sidecar settings moved to Settings → Audiobooks: saving the Komga
     * connection form must no longer touch the qbittorrent row's config blob
     * (writeGrimmorySidecars et al.) nor the grimmory row's options['native'].
     */
    public function testSubmitDoesNotTouchSidecarConfigOrNativeOptions(): void
    {
        $this->seedGrimmoryRow();
        $qbId = $this->seedQbittorrentConfig([
            'category'              => 'custom-cat',
            'removeOnComplete'      => false,
            'stagingSubdir'         => 'staging-x',
            'writeGrimmorySidecars' => false,
        ]);

        $this->client->loginUser($this->loadAdmin());

        $crawler = $this->client->request('GET', '/settings/grimmory');
        $form = $crawler->selectButton('Save settings')->form();
        $form['grimmory_integration[baseUrl]'] = 'http://komga:25601';
        $this->client->submit($form);
        self::assertResponseRedirects('/settings/grimmory');

        $this->em->clear();
        // The qbittorrent config blob is untouched — the non-default sidecar
        // toggle and every other key survive.
        $config = $this->integrations->getTorrentClientConfig();
        self::assertFalse($config->writeGrimmorySidecars);
        self::assertSame('custom-cat', $config->category);
        self::assertFalse($config->removeOnComplete);
        self::assertSame('staging-x', $config->stagingSubdir);
        // The qbittorrent row itself (connection + enabled flag) is untouched.
        $qbittorrent = $this->integrations->findByKind(Integration::KIND_QBITTORRENT);
        self::assertNotNull($qbittorrent);
        self::assertSame($qbId, $qbittorrent->getId());
        self::assertTrue($qbittorrent->isEnabled());
        self::assertSame('http://qbittorrent:8080', $qbittorrent->getBaseUrl());
        self::assertSame('admin', $qbittorrent->getCredentials()['username'] ?? null);
        self::assertSame('adminpass', $qbittorrent->getCredentials()['password'] ?? null);
        // The grimmory row saved its connection but kept its options blob intact.
        $grimmory = $this->integrations->findByKind(Integration::KIND_GRIMMORY);
        self::assertNotNull($grimmory);
        self::assertSame('http://komga:25601', $grimmory->getBaseUrl());
        self::assertSame('komga-user', $grimmory->getCredentials()['username'] ?? null);
        self::assertSame('komga-pass', $grimmory->getCredentials()['password'] ?? null);
        // assertEquals: jsonb storage does not preserve key order.
        self::assertEquals([
            'username'      => 'meta-user',
            'password'      => 'meta-pass',
            'sidecarImport' => true,
        ], $grimmory->getOptions()['native'] ?? null);
        self::assertSame('keep-me', $grimmory->getOptions()['other'] ?? null);
    }

    /**
     * Store torrent-client config overrides the way Settings → Torrents would:
     * into the qbittorrent row's options['config'] blob, connection fields set.
     *
     * @param array<string, mixed> $overrides
     */
    private function seedQbittorrentConfig(array $overrides): ?int
    {
        // Drop the per-request memo: it caches null lookups, and reusing the
        // repository across requests would otherwise re-create existing rows.
        $this->integrations->clearSettingsCache();
        $qbittorrent = $this->integrations->getOrCreate(Integration::KIND_QBITTORRENT);
        $qbittorrent->setAuthType(Integration::AUTH_BASIC);
        $qbittorrent->setBaseUrl('http://qbittorrent:8080');
        $qbittorrent->setCredentials(['username' => 'admin', 'password' => 'adminpass']);
        $qbittorrent->setEnabled(true);
        $config = TorrentClientConfig::fromArray(array_replace(
            $this->integrations->getTorrentClientConfig()->toArray(),
            $overrides,
        ));
        $options = $qbittorrent->getOptions();
        $options['config'] = $config->toArray();
        $qbittorrent->setOptions($options);
        if ($qbittorrent->getId() === null) {
            $this->em->persist($qbittorrent);
        }
        $this->em->flush();

        return $qbittorrent->getId();
    }

    private function seedGrimmoryRow(): void
    {
        $this->integrations->clearSettingsCache();
        $grimmory = $this->integrations->getOrCreate(Integration::KIND_GRIMMORY);
        $grimmory->setAuthType(Integration::AUTH_BASIC);
        $grimmory->setBaseUrl('http://komga:25600');
        $grimmory->setCredentials(['username' => 'komga-user', 'password' => 'komga-pass']);
        $grimmory->setEnabled(true);
        $grimmory->setOptions([
            'native' => [
                'username'      => 'meta-user',
                'password'      => 'meta-pass',
                'sidecarImport' => true,
            ],
            'other' => 'keep-me',
        ]);
        if ($grimmory->getId() === null) {
            $this->em->persist($grimmory);
        }
        $this->em->flush();
    }

    private function seedAdmin(): void
    {
        $hasher = self::getContainer()->get('security.user_password_hasher');
        $user = new User('admin-grim');
        $user->setRoles([User::ROLE_ADMIN]);
        $user->setPassword($hasher->hashPassword($user, 'doesnt-matter'));
        $this->em->persist($user);
        $this->em->flush();
    }

    private function loadAdmin(): User
    {
        $user = self::getContainer()->get('doctrine')
            ->getRepository(User::class)
            ->findOneBy(['username' => 'admin-grim']);
        self::assertNotNull($user);
        return $user;
    }
}
