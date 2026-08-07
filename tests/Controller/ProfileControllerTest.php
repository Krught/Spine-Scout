<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Book;
use App\Entity\BookRequest;
use App\Entity\DownloadJob;
use App\Entity\Integration;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Covers the profile page: account facts render, and the self-service password
 * change validates the current password, length, confirmation and CSRF before
 * re-hashing.
 */
final class ProfileControllerTest extends WebTestCase
{
    private const CURRENT_PASSWORD = 'correct-password-123';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->em->createQuery('DELETE FROM ' . DownloadJob::class)->execute();
        $this->em->createQuery('DELETE FROM ' . BookRequest::class)->execute();
        $this->em->createQuery('DELETE FROM ' . Book::class)->execute();
        $this->em->createQuery('DELETE FROM ' . Integration::class)->execute();
        $this->em->createQuery('DELETE FROM ' . User::class)->execute();
        $this->seedUser('profile-user');
        $this->em->clear();
    }

    public function testPageRendersAccountFactsAndPasswordForm(): void
    {
        $this->client->loginUser($this->loadUser('profile-user'));

        $crawler = $this->client->request('GET', '/profile');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('profile-user', $this->html());
        self::assertStringContainsString('Member since', $this->html());
        self::assertCount(1, $crawler->filter('form[action="/profile/password"] input[name="_token"]'));
        self::assertCount(1, $crawler->filter('input[name="current_password"]'));
        self::assertCount(1, $crawler->filter('input[name="password"]'));
        self::assertCount(1, $crawler->filter('input[name="password_confirm"]'));
    }

    public function testAnonymousVisitorIsRedirectedToLogin(): void
    {
        $this->client->request('GET', '/profile');

        self::assertResponseStatusCodeSame(302);
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testWrongCurrentPasswordIsRejected(): void
    {
        $this->client->loginUser($this->loadUser('profile-user'));

        $this->postPasswordChange([
            'current_password' => 'not-the-current-password',
            'password'         => 'brand-new-password',
            'password_confirm' => 'brand-new-password',
        ]);

        self::assertResponseRedirects('/profile');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Your current password is incorrect.');
        $this->assertPasswordIs(self::CURRENT_PASSWORD);
    }

    public function testTooShortNewPasswordIsRejected(): void
    {
        $this->client->loginUser($this->loadUser('profile-user'));

        $this->postPasswordChange([
            'current_password' => self::CURRENT_PASSWORD,
            'password'         => 'short',
            'password_confirm' => 'short',
        ]);

        self::assertResponseRedirects('/profile');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Password must be at least 8 characters.');
        $this->assertPasswordIs(self::CURRENT_PASSWORD);
    }

    public function testMismatchedConfirmationIsRejected(): void
    {
        $this->client->loginUser($this->loadUser('profile-user'));

        $this->postPasswordChange([
            'current_password' => self::CURRENT_PASSWORD,
            'password'         => 'brand-new-password',
            'password_confirm' => 'a-different-password',
        ]);

        self::assertResponseRedirects('/profile');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'The two passwords do not match.');
        $this->assertPasswordIs(self::CURRENT_PASSWORD);
    }

    public function testInvalidCsrfTokenIsRejected(): void
    {
        $this->client->loginUser($this->loadUser('profile-user'));

        $this->client->request('POST', '/profile/password', [
            '_token'           => 'not-a-valid-token',
            'current_password' => self::CURRENT_PASSWORD,
            'password'         => 'brand-new-password',
            'password_confirm' => 'brand-new-password',
        ]);

        self::assertResponseRedirects('/profile');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Invalid CSRF token.');
        $this->assertPasswordIs(self::CURRENT_PASSWORD);
    }

    public function testSuccessfulChangeRehashesAndTheNewPasswordSignsIn(): void
    {
        $this->client->loginUser($this->loadUser('profile-user'));
        $oldHash = $this->loadUser('profile-user')->getPassword();

        $this->postPasswordChange([
            'current_password' => self::CURRENT_PASSWORD,
            'password'         => 'brand-new-password',
            'password_confirm' => 'brand-new-password',
        ]);

        self::assertResponseRedirects('/profile');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Your password has been changed.');

        $this->em->clear();
        $user = $this->loadUser('profile-user');
        self::assertNotSame($oldHash, $user->getPassword());
        $this->assertPasswordIs('brand-new-password');

        // The new password actually signs in through the login form: drop the
        // session, log in from scratch, and land back on an authenticated page.
        $this->client->restart();
        $crawler = $this->client->request('GET', '/login');
        self::assertResponseIsSuccessful();
        $loginToken = (string) $crawler->filter('input[name="_csrf_token"]')->attr('value');
        $this->client->request('POST', '/login', [
            '_username'   => 'profile-user',
            '_password'   => 'brand-new-password',
            '_csrf_token' => $loginToken,
        ]);
        self::assertResponseStatusCodeSame(302);
        self::assertStringNotContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));

        $this->client->request('GET', '/profile');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('profile-user', $this->html());
    }

    /**
     * Submit the password form with the CSRF token scraped from the rendered
     * page — `profile_password` is a stateful (session-backed) token id.
     *
     * @param array<string, string> $fields
     */
    private function postPasswordChange(array $fields): void
    {
        $crawler = $this->client->request('GET', '/profile');
        self::assertResponseIsSuccessful();
        $token = (string) $crawler->filter('form[action="/profile/password"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/profile/password', $fields + ['_token' => $token]);
    }

    private function assertPasswordIs(string $plain): void
    {
        $this->em->clear();
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($this->loadUser('profile-user'), $plain));
    }

    private function html(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }

    private function seedUser(string $username): void
    {
        $hasher = self::getContainer()->get('security.user_password_hasher');
        $user = new User($username);
        $user->setPassword($hasher->hashPassword($user, self::CURRENT_PASSWORD));
        $this->em->persist($user);
        $this->em->flush();
    }

    private function loadUser(string $username): User
    {
        $user = self::getContainer()->get('doctrine')->getRepository(User::class)->findOneBy(['username' => $username]);
        self::assertNotNull($user);

        return $user;
    }
}
