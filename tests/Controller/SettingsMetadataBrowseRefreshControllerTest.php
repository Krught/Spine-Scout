<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Integration;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Settings → Metadata owns the browse pool's freshness threshold: "refresh browse data in the
 * background when it is older than N minutes" (default 2h). It is deliberately its own form,
 * separate from the Hardcover integration form on the same page — the Hardcover row's
 * `syncIntervalMinutes` paces the trending *sync*, which an operator may run once a day while
 * still wanting browse to revalidate every couple of hours.
 */
final class SettingsMetadataBrowseRefreshControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->em->createQuery('DELETE FROM ' . Integration::class)->execute();
        $this->em->createQuery('DELETE FROM ' . User::class)->execute();
        $this->seedAdmin();
    }

    public function testFieldRendersWithTheTwoHourDefault(): void
    {
        $this->client->loginUser($this->loadAdmin());
        $crawler = $this->client->request('GET', '/settings/metadata');

        self::assertResponseIsSuccessful();
        $field = $crawler->filter('#browse_refresh_minutes');
        self::assertCount(1, $field);
        self::assertSame((string) Integration::DEFAULT_BROWSE_REFRESH_MINUTES, $field->attr('value'));
        self::assertSame((string) Integration::MIN_BROWSE_REFRESH_MINUTES, $field->attr('min'));
        self::assertSame((string) Integration::MAX_BROWSE_REFRESH_MINUTES, $field->attr('max'));
    }

    public function testSavingPersistsTheThresholdOnTheHardcoverRow(): void
    {
        $this->client->loginUser($this->loadAdmin());
        $this->post(30);

        self::assertResponseRedirects('/settings/metadata');
        self::assertSame(30, $this->storedMinutes());
    }

    /**
     * Out-of-range input clamps rather than erroring — the bounds live in exactly one place
     * (Integration::clampBrowseRefreshMinutes), so a hand-typed 1 or 999999 lands in range.
     */
    public function testOutOfRangeValuesAreClamped(): void
    {
        $this->client->loginUser($this->loadAdmin());

        $this->post(1);
        self::assertSame(Integration::MIN_BROWSE_REFRESH_MINUTES, $this->storedMinutes());

        $this->post(999999);
        self::assertSame(Integration::MAX_BROWSE_REFRESH_MINUTES, $this->storedMinutes());
    }

    /** The purge-threshold form on the same page keeps working — the two POSTs don't collide. */
    public function testSavingTheBrowseThresholdLeavesThePurgeThresholdAlone(): void
    {
        $this->client->loginUser($this->loadAdmin());
        // Save a non-default purge threshold first: both settings live in the same JSONB blob on
        // the Hardcover row, so a merge bug here would silently reset the other one.
        $this->postField('purge_threshold', '90');
        self::assertSame(90, $this->hardcoverFresh()->getBookPurgeThresholdDays());

        $this->post(45);

        self::assertSame(45, $this->storedMinutes());
        self::assertSame(90, $this->hardcover()->getBookPurgeThresholdDays());
    }

    private function post(int $minutes): void
    {
        $this->postField('browse_refresh_minutes', (string) $minutes);
    }

    /** Posts one of the metadata page's plain (non-Symfony-Form) settings forms, by field id. */
    private function postField(string $field, string $value): void
    {
        // Stateless CSRF tokens are derived from request context, so pull the rendered one out
        // of *this* form (the page carries several).
        $crawler = $this->client->request('GET', '/settings/metadata');
        $token = $crawler
            ->filterXPath(sprintf('//input[@id="%s"]/ancestor::form//input[@name="_token"]', $field))
            ->attr('value');
        self::assertNotNull($token);

        $this->client->request('POST', '/settings/metadata', ['_token' => $token, $field => $value]);
    }

    private function hardcoverFresh(): Integration
    {
        $this->em->clear();

        return $this->hardcover();
    }

    private function storedMinutes(): int
    {
        $this->em->clear();

        return $this->hardcover()->getBrowseRefreshAfterMinutes();
    }

    private function hardcover(): Integration
    {
        $row = $this->em->getRepository(Integration::class)->findOneBy(['kind' => Integration::KIND_HARDCOVER]);
        self::assertInstanceOf(Integration::class, $row);

        return $row;
    }

    private function seedAdmin(): void
    {
        $hasher = self::getContainer()->get('security.user_password_hasher');
        $user = new User('admin-metadata');
        $user->setRoles([User::ROLE_ADMIN]);
        $user->setPassword($hasher->hashPassword($user, 'doesnt-matter'));
        $this->em->persist($user);
        $this->em->flush();
    }

    private function loadAdmin(): User
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['username' => 'admin-metadata']);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }
}
