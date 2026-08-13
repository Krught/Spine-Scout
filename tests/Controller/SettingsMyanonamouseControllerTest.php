<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Integration;
use App\Entity\User;
use App\Repository\IntegrationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SettingsMyanonamouseControllerTest extends WebTestCase
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

    public function testGetRendersDownloadingFieldset(): void
    {
        $this->client->loginUser($this->loadAdmin());
        $this->client->request('GET', '/settings/myanonamouse');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2.panel-title', 'MyAnonamouse');
        self::assertSelectorTextContains('body', 'Downloading');
        self::assertSelectorExists('input[name="alwaysUseWedge"][type="checkbox"]');
        self::assertSelectorExists('input[name="autoWedgeMinGb"][type="number"]');
        // Neither wedge knob is on by default.
        self::assertSelectorNotExists('input[name="alwaysUseWedge"][checked]');
        self::assertSelectorExists('input[name="autoWedgeMinGb"][value=""]');
        // The help text spells out the cost.
        self::assertSelectorTextContains('body', 'Wedges are spent from your bonus points');
    }

    public function testPostRoundTripsWedgeFields(): void
    {
        $this->client->loginUser($this->loadAdmin());
        $token = $this->fetchCsrfToken('/settings/myanonamouse');

        $this->client->request('POST', '/settings/myanonamouse', [
            '_token'         => $token,
            'enabled'        => '1',
            'alwaysUseWedge' => '1',
            'autoWedgeMinGb' => '2.5',
        ]);

        self::assertResponseRedirects('/settings/myanonamouse');

        $this->em->clear();
        $config = $this->integrations->getMyAnonamouseConfig();
        self::assertTrue($config->alwaysUseWedge);
        self::assertSame(2.5, $config->autoWedgeMinGb);

        // The saved page re-renders both values.
        $this->client->followRedirect();
        self::assertSelectorExists('input[name="alwaysUseWedge"][checked]');
        self::assertSelectorExists('input[name="autoWedgeMinGb"][value="2.5"]');
    }

    public function testBlankAutoWedgeMinGbStaysNullAndUntickedCheckboxClears(): void
    {
        $this->client->loginUser($this->loadAdmin());

        // Seed non-default values first.
        $token = $this->fetchCsrfToken('/settings/myanonamouse');
        $this->client->request('POST', '/settings/myanonamouse', [
            '_token'         => $token,
            'alwaysUseWedge' => '1',
            'autoWedgeMinGb' => '3',
        ]);
        self::assertResponseRedirects('/settings/myanonamouse');

        // Save again with the checkbox unticked and the number box blank.
        $token = $this->fetchCsrfToken('/settings/myanonamouse');
        $this->client->request('POST', '/settings/myanonamouse', [
            '_token'         => $token,
            'autoWedgeMinGb' => '',
        ]);
        self::assertResponseRedirects('/settings/myanonamouse');

        $this->em->clear();
        $config = $this->integrations->getMyAnonamouseConfig();
        self::assertFalse($config->alwaysUseWedge);
        self::assertNull($config->autoWedgeMinGb);
    }

    public function testZeroAutoWedgeMinGbClampsToNull(): void
    {
        $this->client->loginUser($this->loadAdmin());
        $token = $this->fetchCsrfToken('/settings/myanonamouse');

        $this->client->request('POST', '/settings/myanonamouse', [
            '_token'         => $token,
            'autoWedgeMinGb' => '0',
        ]);
        self::assertResponseRedirects('/settings/myanonamouse');

        $this->em->clear();
        self::assertNull($this->integrations->getMyAnonamouseConfig()->autoWedgeMinGb);
    }

    public function testStatusPillShowsAccountStatsWhenASnapshotExists(): void
    {
        $this->client->loginUser($this->loadAdmin());
        $token = $this->fetchCsrfToken('/settings/myanonamouse');
        $this->client->request('POST', '/settings/myanonamouse', ['_token' => $token, 'enabled' => '1']);
        self::assertResponseRedirects('/settings/myanonamouse');

        $this->integrations->saveMamAccountState([
            'username'   => 'bookmouse',
            'class'      => 'Elite VIP',
            'ratio'      => '12.34',
            'seedbonus'  => 51234,
            'wedges'     => 7,
            'unsatCount' => 2,
            'unsatLimit' => 5,
            'uploaded'   => '4.2 TiB',
            'downloaded' => '349.1 GiB',
            'checkedAt'  => '2026-08-12T10:00:00+00:00',
        ]);
        $this->em->clear();

        $this->client->request('GET', '/settings/myanonamouse');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.mam-account-stats');
        self::assertSelectorTextContains('.status-pill', 'bookmouse (Elite VIP)');
        self::assertSelectorTextContains('.mam-account-stats', '51,234');
        self::assertSelectorTextContains('.mam-account-stats', '7');
        self::assertSelectorTextContains('.mam-account-stats', '12.34');
        self::assertSelectorTextContains('.mam-account-stats', '4.2 TiB / 349.1 GiB');
        self::assertSelectorTextContains('.mam-account-stats', '2 / 5');
    }

    public function testNoAccountStatsStripBeforeTheFirstSnapshot(): void
    {
        $this->client->loginUser($this->loadAdmin());
        $token = $this->fetchCsrfToken('/settings/myanonamouse');
        $this->client->request('POST', '/settings/myanonamouse', ['_token' => $token, 'enabled' => '1']);
        self::assertResponseRedirects('/settings/myanonamouse');

        $this->client->followRedirect();
        self::assertSelectorExists('.status-pill');
        self::assertSelectorNotExists('.mam-account-stats');
    }

    public function testRejectsInvalidCsrfToken(): void
    {
        $this->client->loginUser($this->loadAdmin());
        $this->client->request('POST', '/settings/myanonamouse', ['_token' => 'nope']);
        self::assertResponseRedirects('/settings/myanonamouse');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Invalid CSRF token');
    }

    public function testRequiresAdminRole(): void
    {
        $this->client->request('GET', '/settings/myanonamouse');
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    private function seedAdmin(): void
    {
        $hasher = self::getContainer()->get('security.user_password_hasher');
        $user = new User('admin-mam');
        $user->setRoles([User::ROLE_ADMIN]);
        $user->setPassword($hasher->hashPassword($user, 'doesnt-matter'));
        $this->em->persist($user);
        $this->em->flush();
    }

    private function loadAdmin(): User
    {
        $user = self::getContainer()->get('doctrine')
            ->getRepository(User::class)
            ->findOneBy(['username' => 'admin-mam']);
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
