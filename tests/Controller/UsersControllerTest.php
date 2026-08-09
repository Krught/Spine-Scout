<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class UsersControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private UserRepository $users;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->users = self::getContainer()->get(UserRepository::class);
        $this->em->createQuery('DELETE FROM '.User::class)->execute();
    }

    public function testManagerSeesUserList(): void
    {
        $master = $this->seedUser('master', [User::ROLE_ADMIN], master: true);
        $this->seedUser('regular', []);
        $this->client->loginUser($master);

        $this->client->request('GET', '/users');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.users-table', 'regular');
        self::assertSelectorTextContains('.users-badge--master', 'Master admin');
    }

    public function testNonPrivilegedUserIsForbidden(): void
    {
        $this->seedUser('master', [User::ROLE_ADMIN], master: true);
        $regular = $this->seedUser('regular', []);
        $this->client->loginUser($regular);

        $this->client->request('GET', '/users');

        self::assertResponseStatusCodeSame(403);
    }

    public function testManageSettingsUserCanReachSettingsButNotUsers(): void
    {
        $this->seedUser('master', [User::ROLE_ADMIN], master: true);
        $settingsManager = $this->seedUser('cfg', [User::ROLE_MANAGE_SETTINGS]);
        $this->client->loginUser($settingsManager);

        $this->client->request('GET', '/settings/general');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/users');
        self::assertResponseStatusCodeSame(403);
    }

    public function testCreateUserWithCapabilities(): void
    {
        $master = $this->seedUser('master', [User::ROLE_ADMIN], master: true);
        $this->client->loginUser($master);

        $crawler = $this->client->request('GET', '/users');
        $token = $crawler->filter('[data-user-modal-create-token-param]')
            ->attr('data-user-modal-create-token-param');

        $this->client->request('POST', '/users/create', [
            '_token' => $token,
            'username' => 'alice',
            'password' => 'password-123',
            'password_confirm' => 'password-123',
            'manage_settings' => '1',
            'interactive_search' => '1',
            'reimport' => '1',
            'view_freeleech' => '1',
            'auto_approve' => '1',
        ]);

        self::assertResponseRedirects('/users');
        $alice = $this->users->findOneByUsername('alice');
        self::assertNotNull($alice);
        self::assertTrue($alice->canManageSettings());
        self::assertFalse($alice->canManageUsers());
        self::assertTrue($alice->canUseInteractiveSearch());
        self::assertTrue($alice->canReimport());
        self::assertTrue($alice->canViewFreeleech());
        self::assertTrue($alice->isAutoApproveRequests());
        self::assertFalse($alice->isMaster());
    }

    public function testUpdateUserChangesPermissions(): void
    {
        $master = $this->seedUser('master', [User::ROLE_ADMIN], master: true);
        $bob = $this->seedUser('bob', [User::ROLE_MANAGE_SETTINGS]);
        $this->client->loginUser($master);

        $token = $this->editToken($bob->getId());
        $this->client->request('POST', '/users/'.$bob->getId().'/update', [
            '_token' => $token,
            'username' => 'bob',
            'manage_users' => '1',
            'reimport' => '1',
            'auto_approve' => '1',
        ]);

        self::assertResponseRedirects('/users');
        $this->em->clear();
        $bob = $this->users->findOneByUsername('bob');
        self::assertTrue($bob->canManageUsers());
        self::assertFalse($bob->canManageSettings());
        self::assertTrue($bob->canReimport());
        self::assertTrue($bob->isAutoApproveRequests());

        // The edit button surfaces the granted flag; revoking round-trips to off.
        $crawler = $this->client->request('GET', '/users');
        self::assertSame('1', $crawler->filter('button[data-user-modal-id-param="'.$bob->getId().'"]')
            ->attr('data-user-modal-reimport-param'));
        $this->client->request('POST', '/users/'.$bob->getId().'/update', [
            '_token' => $this->editToken($bob->getId()),
            'username' => 'bob',
        ]);
        self::assertResponseRedirects('/users');
        $this->em->clear();
        self::assertFalse($this->users->findOneByUsername('bob')->canReimport());
    }

    public function testInteractiveSearchCapabilityGrantAndRevokeRoundTrip(): void
    {
        $master = $this->seedUser('master', [User::ROLE_ADMIN], master: true);
        $carol = $this->seedUser('carol', []);
        $this->client->loginUser($master);

        self::assertFalse($carol->canUseInteractiveSearch(), 'Regular users start without interactive search.');
        self::assertTrue($master->canUseInteractiveSearch(), 'The master admin always has interactive search.');

        // Grant.
        $this->client->request('POST', '/users/'.$carol->getId().'/update', [
            '_token' => $this->editToken($carol->getId()),
            'username' => 'carol',
            'interactive_search' => '1',
        ]);
        self::assertResponseRedirects('/users');
        $this->em->clear();
        $carol = $this->users->findOneByUsername('carol');
        self::assertTrue($carol->canUseInteractiveSearch());
        self::assertFalse($carol->canManageSettings());
        self::assertFalse($carol->canManageUsers());

        // The edit button reflects the granted state.
        $crawler = $this->client->request('GET', '/users');
        self::assertSame('1', $crawler->filter('button[data-user-modal-id-param="'.$carol->getId().'"]')
            ->attr('data-user-modal-interactive-search-param'));

        // Revoke.
        $this->client->request('POST', '/users/'.$carol->getId().'/update', [
            '_token' => $this->editToken($carol->getId()),
            'username' => 'carol',
        ]);
        self::assertResponseRedirects('/users');
        $this->em->clear();
        $carol = $this->users->findOneByUsername('carol');
        self::assertFalse($carol->canUseInteractiveSearch());
    }

    public function testMasterCannotBeDeleted(): void
    {
        $master = $this->seedUser('master', [User::ROLE_ADMIN], master: true);
        $this->client->loginUser($master);

        // The master's delete button is rendered disabled (UI guard).
        $crawler = $this->client->request('GET', '/users');
        $deleteForm = $crawler->filter('form[action="/users/'.$master->getId().'/delete"]');
        self::assertCount(1, $deleteForm->filter('button[disabled]'));

        // Even with a valid token, the server-side guard rejects the master delete.
        $token = $deleteForm->filter('input[name="_token"]')->attr('value');
        $this->client->request('POST', '/users/'.$master->getId().'/delete', ['_token' => $token]);

        self::assertResponseRedirects('/users');
        self::assertNotNull($this->users->find($master->getId()));
    }

    public function testDeleteRegularUser(): void
    {
        $master = $this->seedUser('master', [User::ROLE_ADMIN], master: true);
        $bob = $this->seedUser('bob', []);
        $this->client->loginUser($master);

        $crawler = $this->client->request('GET', '/users');
        $deleteToken = $crawler->filter('form[action="/users/'.$bob->getId().'/delete"] input[name="_token"]')
            ->attr('value');

        $this->client->request('POST', '/users/'.$bob->getId().'/delete', ['_token' => $deleteToken]);

        self::assertResponseRedirects('/users');
        self::assertNull($this->users->find($bob->getId()));
    }

    private function editToken(int $id): string
    {
        $crawler = $this->client->request('GET', '/users');

        return $crawler->filter('button[data-user-modal-id-param="'.$id.'"]')
            ->attr('data-user-modal-update-token-param');
    }

    /** @param list<string> $roles */
    private function seedUser(string $username, array $roles, bool $master = false): User
    {
        $hasher = self::getContainer()->get('security.user_password_hasher');
        $user = new User($username);
        $user->setRoles($roles);
        $user->setMaster($master);
        $user->setPassword($hasher->hashPassword($user, 'correct-password-123'));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
