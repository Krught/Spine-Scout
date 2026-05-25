<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SetupControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private UserRepository $users;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->users = $container->get(UserRepository::class);

        $this->em->createQuery('DELETE FROM '.User::class)->execute();
    }

    public function testEmptyDatabaseRedirectsHomeToSetup(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseRedirects('/setup');
    }

    public function testStepOneRendersWhenNoUsers(): void
    {
        $this->client->request('GET', '/setup');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Welcome to Spine Scout');
        self::assertSelectorExists('input[name="username"]');
    }

    public function testStepTwoBouncesToStepOneWithoutSessionUsername(): void
    {
        $this->client->request('GET', '/setup/password');

        self::assertResponseRedirects('/setup');
    }

    public function testFullWizardCreatesAdminAndLogsIn(): void
    {
        $crawler = $this->client->request('GET', '/setup');
        $form = $crawler->selectButton('Next')->form([
            'username' => 'First-Admin',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects('/setup/password');

        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Set your password');
        self::assertSelectorTextContains('.setup-username', 'first-admin');

        $form = $crawler->selectButton('Create account')->form([
            'password'         => 'super-secret-pw',
            'password_confirm' => 'super-secret-pw',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects('/');

        $user = $this->users->findOneByUsername('first-admin');
        self::assertNotNull($user);
        self::assertSame('first-admin', $user->getUsername());
        self::assertContains(User::ROLE_ADMIN, $user->getRoles());

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    public function testInvalidUsernameStaysOnStepOne(): void
    {
        $crawler = $this->client->request('GET', '/setup');
        $form = $crawler->selectButton('Next')->form([
            'username' => 'no',
        ]);
        $this->client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.setup-error', 'between');
    }

    public function testPasswordMismatchStaysOnStepTwo(): void
    {
        $crawler = $this->client->request('GET', '/setup');
        $this->client->submit($crawler->selectButton('Next')->form(['username' => 'admin']));
        $crawler = $this->client->followRedirect();

        $form = $crawler->selectButton('Create account')->form([
            'password'         => 'one-strong-pass',
            'password_confirm' => 'two-strong-pass',
        ]);
        $this->client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.setup-error', 'do not match');
        self::assertNull($this->users->findOneByUsername('admin'));
    }

    public function testSetupIs404WhenUsersAlreadyExist(): void
    {
        $this->seedAdmin();

        $this->client->request('GET', '/setup');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/setup/password');
        self::assertResponseStatusCodeSame(404);
    }

    private function seedAdmin(): void
    {
        $hasher = self::getContainer()->get('security.user_password_hasher');
        $user = new User('existing');
        $user->setRoles([User::ROLE_ADMIN]);
        $user->setPassword($hasher->hashPassword($user, 'password-1234'));
        $this->em->persist($user);
        $this->em->flush();
    }
}
