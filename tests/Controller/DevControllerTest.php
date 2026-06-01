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

final class DevControllerTest extends WebTestCase
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
        $this->seedConfig();
    }

    public function testRequiresAdminRole(): void
    {
        $this->client->request('GET', '/development/direct-download');
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testRendersPerSourceToggleCheckboxes(): void
    {
        $this->client->loginUser($this->loadAdmin());
        $this->client->request('GET', '/development/direct-download');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[type="hidden"][name="sources_submitted"]');
        // A checkbox per source, seeded from saved config.
        self::assertSelectorExists('input[name="enabled[]"][value="annas_archive"]');
        self::assertSelectorExists('input[name="enabled[]"][value="libgen"]');
        self::assertSelectorExists('input[name="enabled[]"][value="zlibrary"]');
        self::assertSelectorExists('input[name="enabled[]"][value="welib"]');
    }

    public function testEphemeralToggleNeverPersistsSavedConfig(): void
    {
        $this->client->loginUser($this->loadAdmin());

        // Saved config has annas_archive enabled. Submit the probe with ONLY libgen
        // checked — an ephemeral override for this run.
        $this->client->request('GET', '/development/direct-download', [
            'sources_submitted' => '1',
            'enabled'           => ['libgen'],
            'isbn'              => '9780441478125',
            'submit'            => '1',
        ]);
        self::assertResponseIsSuccessful();

        // Saved settings are untouched: annas_archive still enabled, libgen still
        // disabled exactly as seeded.
        $this->em->clear();
        $config = $this->integrations->getDirectDownloadConfig();
        self::assertTrue($config->isIndexerEnabled('annas_archive'));
        self::assertFalse($config->isIndexerEnabled('libgen'));
    }

    public function testDownloadsPageRendersJobsAndActivitySubTabs(): void
    {
        $this->client->loginUser($this->loadAdmin());
        $this->client->request('GET', '/development/downloads');

        self::assertResponseIsSuccessful();
        // Sub-tab switcher wired to the dev-tabs controller, with both tabs + panes.
        self::assertSelectorExists('[data-controller~="dev-tabs"]');
        self::assertSelectorExists('.dev-subtab[data-pane="jobs"]');
        self::assertSelectorExists('.dev-subtab[data-pane="log"]');
        self::assertSelectorExists('.dev-activity__pane[data-pane="jobs"]');
        self::assertSelectorExists('.dev-activity__pane[data-pane="log"]');
    }

    private function seedAdmin(): void
    {
        $hasher = self::getContainer()->get('security.user_password_hasher');
        $user = new User('admin-dev');
        $user->setRoles([User::ROLE_ADMIN]);
        $user->setPassword($hasher->hashPassword($user, 'doesnt-matter'));
        $this->em->persist($user);
        $this->em->flush();
    }

    private function seedConfig(): void
    {
        $config = DirectDownloadConfig::fromArray([
            'indexerPriority' => [
                ['id' => 'annas_archive', 'enabled' => true],
                ['id' => 'libgen', 'enabled' => false],
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

    private function loadAdmin(): User
    {
        $user = self::getContainer()->get('doctrine')
            ->getRepository(User::class)
            ->findOneBy(['username' => 'admin-dev']);
        self::assertNotNull($user);

        return $user;
    }
}
