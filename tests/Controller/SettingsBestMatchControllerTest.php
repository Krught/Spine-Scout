<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Integration;
use App\Entity\User;
use App\Repository\IntegrationRepository;
use App\Search\BestMatch\BestMatchPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SettingsBestMatchControllerTest extends WebTestCase
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

    public function testGetRendersFormWithDefaultPolicy(): void
    {
        $this->client->loginUser($this->loadAdmin());
        $this->client->request('GET', '/settings/best-match');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2.panel-title', 'Best-match policy');
        self::assertSelectorExists('input[name="_token"]');
        self::assertSelectorExists('input[name="minSeeders"]');
    }

    public function testPostPersistsPolicyAsIntegrationOptions(): void
    {
        $this->client->loginUser($this->loadAdmin());
        $token = $this->fetchCsrfToken('/settings/best-match');
        $this->client->request('POST', '/settings/best-match', [
            '_token'           => $token,
            'formatPriority'   => json_encode(['epub', 'azw3']),
            'sourcePriority'   => json_encode([]),
            'tieBreakers'      => json_encode([BestMatchPolicy::TIE_MOST_SEEDERS]),
            'minSizeBytes'     => '12345',
            'maxSizeBytes'     => '',
            'minSeeders'       => '4',
            'requireIsbnMatch' => '1',
            'languagePriority' => json_encode(['en']),
        ]);

        self::assertResponseRedirects('/settings/best-match');

        $this->em->clear();
        $policy = $this->integrations->getBestMatchPolicy();
        self::assertSame(['epub', 'azw3'], $policy->formatPriority);
        self::assertSame([BestMatchPolicy::TIE_MOST_SEEDERS], $policy->tieBreakers);
        self::assertSame(12345, $policy->minSizeBytes);
        self::assertNull($policy->maxSizeBytes);
        self::assertSame(4, $policy->minSeeders);
        self::assertTrue($policy->requireIsbnMatch);
        self::assertSame(['en'], $policy->languagePriority);

        $row = $this->integrations->findByKind(Integration::KIND_BEST_MATCH);
        self::assertNotNull($row);
        self::assertArrayHasKey('policy', $row->getOptions());
    }

    public function testRejectsInvalidCsrfToken(): void
    {
        $this->client->loginUser($this->loadAdmin());
        $this->client->request('POST', '/settings/best-match', [
            '_token' => 'nope',
            'minSeeders' => '1',
        ]);
        self::assertResponseRedirects('/settings/best-match');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Invalid CSRF token');
    }

    public function testRequiresAdminRole(): void
    {
        $this->client->request('GET', '/settings/best-match');
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    private function seedAdmin(): void
    {
        $hasher = self::getContainer()->get('security.user_password_hasher');
        $user = new User('admin-bm');
        $user->setRoles([User::ROLE_ADMIN]);
        $user->setPassword($hasher->hashPassword($user, 'doesnt-matter'));
        $this->em->persist($user);
        $this->em->flush();
    }

    private function loadAdmin(): User
    {
        $user = self::getContainer()->get('doctrine')
            ->getRepository(User::class)
            ->findOneBy(['username' => 'admin-bm']);
        self::assertNotNull($user);
        return $user;
    }

    /**
     * Stateless CSRF tokens are derived from request context (origin/referer
     * via SameOriginCsrfTokenManager) so the test client must GET the form
     * first and pull the rendered token out of the response.
     */
    private function fetchCsrfToken(string $path): string
    {
        $crawler = $this->client->request('GET', $path);
        $token = $crawler->filter('input[name="_token"]')->attr('value');
        self::assertNotNull($token, "Expected CSRF token rendered at {$path}");
        return $token;
    }
}
