<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\HomeController;
use App\Entity\Book;
use App\Entity\BookRequest;
use App\Entity\BookSectionEntry;
use App\Entity\DownloadJob;
use App\Entity\FreeleechItem;
use App\Entity\Integration;
use App\Entity\User;
use App\Integration\MyAnonamouse\MyAnonamouseConfig;
use App\Repository\IntegrationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Per-user Discover customization: the homepage renders the viewer's own section
 * order, hidden sections are absent from the response entirely, and the save
 * endpoint validates CSRF, drops unknown keys and resets back to the defaults.
 */
final class HomeSectionsControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ?string $token = null;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $c = self::getContainer();
        $this->em = $c->get(EntityManagerInterface::class);
        $c->get(CacheItemPoolInterface::class)->clear();

        $this->em->createQuery('DELETE FROM ' . DownloadJob::class)->execute();
        $this->em->createQuery('DELETE FROM ' . BookRequest::class)->execute();
        $this->em->createQuery('DELETE FROM ' . BookSectionEntry::class)->execute();
        $this->em->createQuery('DELETE FROM ' . FreeleechItem::class)->execute();
        $this->em->createQuery('DELETE FROM ' . Book::class)->execute();
        $this->em->createQuery('DELETE FROM ' . Integration::class)->execute();
        $this->em->createQuery('DELETE FROM ' . User::class)->execute();
        $this->seedUser('home-user');
        $this->seedUser('freeleech-user', [User::ROLE_VIEW_FREELEECH]);
        $this->em->clear();
    }

    public function testHomepageRendersTheShippedOrderAndTheCustomizeAffordance(): void
    {
        $this->client->loginUser($this->loadUser('home-user'));
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        // Freeleech is off, so it is not available to anyone here.
        self::assertSame(
            ['recent', 'trending', 'new_releases', 'upcoming', 'genres', 'staff_picks', 'authors', 'requests'],
            $this->renderedKeys(),
        );
        $html = $this->html();
        self::assertStringContainsString('data-controller="home-sections"', $html);
        self::assertStringContainsString('Customize Discover', $html);
        // Every allowed section is offered in the panel, enabled by default.
        self::assertStringContainsString('&quot;id&quot;:&quot;requests&quot;,&quot;enabled&quot;:true', $html);
    }

    public function testSavedOrderAndHiddenSetDriveTheRenderedPage(): void
    {
        $this->client->loginUser($this->loadUser('home-user'));
        $this->client->request('GET', '/');

        $this->post([
            'order'  => ['requests', 'genres', 'recent', 'trending', 'new_releases', 'upcoming', 'staff_picks', 'authors'],
            'hidden' => ['trending', 'authors'],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(['ok' => true], $this->json());

        $user = $this->loadUser('home-user');
        self::assertSame(['requests', 'genres', 'recent', 'trending', 'new_releases', 'upcoming', 'staff_picks', 'authors'], $user->getHomeSectionsOrder());
        self::assertSame(['trending', 'authors'], $user->getHiddenHomeSections());

        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSame(
            ['requests', 'genres', 'recent', 'new_releases', 'upcoming', 'staff_picks'],
            $this->renderedKeys(),
        );
        // A hidden row is not rendered at all — not merely display:none.
        self::assertStringNotContainsString('>Popular Authors</h2>', $this->html());
        // …but it is still offered in the customize panel so it can be switched back on.
        self::assertStringContainsString('&quot;id&quot;:&quot;authors&quot;,&quot;enabled&quot;:false', $this->html());
    }

    /** Keys the stored order never heard of land at their shipped position, visible. */
    public function testKeysMissingFromTheStoredOrderKeepTheirDefaultPosition(): void
    {
        $this->client->loginUser($this->loadUser('home-user'));
        $this->client->request('GET', '/');
        $this->post(['order' => ['requests', 'recent'], 'hidden' => []]);

        $this->client->request('GET', '/');
        self::assertSame(
            ['requests', 'recent', 'trending', 'new_releases', 'upcoming', 'genres', 'staff_picks', 'authors'],
            $this->renderedKeys(),
        );
    }

    public function testUnknownKeysAreDroppedSilently(): void
    {
        $this->client->loginUser($this->loadUser('home-user'));
        $this->client->request('GET', '/');
        $this->post(['order' => ['requests', 'not_a_section', 'recent'], 'hidden' => ['nope', 'recent']]);

        self::assertResponseIsSuccessful();
        $user = $this->loadUser('home-user');
        self::assertSame(['requests', 'recent'], $user->getHomeSectionsOrder());
        self::assertSame(['recent'], $user->getHiddenHomeSections());
    }

    public function testResetClearsTheStoredPreference(): void
    {
        $this->client->loginUser($this->loadUser('home-user'));
        $this->client->request('GET', '/');
        $this->post(['order' => ['requests'], 'hidden' => ['recent']]);
        self::assertSame(['recent'], $this->loadUser('home-user')->getHiddenHomeSections());

        $this->post(['reset' => true]);
        self::assertResponseIsSuccessful();

        $user = $this->loadUser('home-user');
        self::assertSame([], $user->getHomeSectionsOrder());
        self::assertSame([], $user->getHiddenHomeSections());

        $this->client->request('GET', '/');
        self::assertSame(HomeController::DEFAULT_SECTION_ORDER, [...$this->renderedKeys(), 'freeleech']);
    }

    public function testInvalidCsrfTokenIsRejectedAndNothingIsStored(): void
    {
        $this->client->loginUser($this->loadUser('home-user'));
        $this->client->request(
            'POST',
            '/home/sections',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            json_encode(['_token' => 'not-the-token', 'order' => ['requests']], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(403);
        self::assertSame('Invalid CSRF token.', $this->json()['error']);
        self::assertSame([], $this->loadUser('home-user')->getHomeSectionsOrder());
    }

    public function testAnonymousVisitorCannotSave(): void
    {
        $this->client->request('POST', '/home/sections');

        self::assertResponseStatusCodeSame(302);
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    /** Availability wins: a stored order can never resurrect a gated row. */
    public function testStoredOrderCannotResurrectTheGatedFreeleechRow(): void
    {
        $this->enableMyAnonamouse();
        $this->client->loginUser($this->loadUser('freeleech-user'));
        $this->client->request('GET', '/');
        self::assertContains('freeleech', $this->renderedKeys());
        $this->post(['order' => ['freeleech', 'recent'], 'hidden' => []]);

        // Same stored preference, a viewer without the capability: no freeleech row.
        $granted = $this->loadUser('freeleech-user');
        $plain = $this->loadUser('home-user');
        $plain->setHomeSections(['order' => $granted->getHomeSectionsOrder(), 'hidden' => []]);
        $this->em->flush();

        $this->client->loginUser($this->loadUser('home-user'));
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertNotContains('freeleech', $this->renderedKeys());
        self::assertSame('recent', $this->renderedKeys()[0]);
    }

    /** @param array<string, mixed> $payload */
    private function post(array $payload): void
    {
        $payload['_token'] = $this->csrfToken();
        $this->client->request(
            'POST',
            '/home/sections',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    /** The token the customize panel was rendered with (the page must be loaded first). */
    private function csrfToken(): string
    {
        if ($this->token === null) {
            self::assertMatchesRegularExpression('/data-home-sections-token-value="([^"]+)"/', $this->html());
            preg_match('/data-home-sections-token-value="([^"]+)"/', $this->html(), $m);
            $this->token = html_entity_decode($m[1]);
        }

        return $this->token;
    }

    /** @return list<string> */
    private function renderedKeys(): array
    {
        preg_match_all('/<section class="row" data-section-key="([a-z_]+)"/', $this->html(), $m);

        return $m[1];
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode($this->html(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function html(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }

    private function enableMyAnonamouse(): void
    {
        /** @var IntegrationRepository $integrations */
        $integrations = self::getContainer()->get(IntegrationRepository::class);
        $integrations->saveMyAnonamouseConfig(
            new MyAnonamouseConfig(enabled: true, showOnHomepage: true, showBrowseShelf: true, fetchVipFreeleech: false),
            true,
            $this->em,
        );
        $this->em->flush();
        $integrations->clearSettingsCache();
    }

    /** @param list<string> $roles */
    private function seedUser(string $username, array $roles = []): void
    {
        $hasher = self::getContainer()->get('security.user_password_hasher');
        $user = new User($username);
        $user->setPassword($hasher->hashPassword($user, 'x'));
        if ($roles !== []) {
            $user->setRoles($roles);
        }
        $this->em->persist($user);
        $this->em->flush();
    }

    private function loadUser(string $username): User
    {
        $this->em->clear();
        $user = self::getContainer()->get('doctrine')->getRepository(User::class)->findOneBy(['username' => $username]);
        self::assertNotNull($user);

        return $user;
    }
}
