<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * User management. Capability-gated by ROLE_MANAGE_USERS (the master admin and
 * anyone granted "Manage users"). The first-created user is a protected master:
 * never deletable, capabilities locked to full admin.
 */
#[IsGranted('ROLE_MANAGE_USERS')]
final class UsersController extends AbstractController
{
    #[Route('/users', name: 'users', methods: ['GET'])]
    public function index(UserRepository $users): Response
    {
        return $this->render('users/index.html.twig', [
            'users' => $users->findAllOrdered(),
        ]);
    }

    #[Route('/users/create', name: 'users_create', methods: ['POST'])]
    public function create(
        Request $request,
        UserRepository $users,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
    ): Response {
        if (!$this->isCsrfTokenValid('users_create', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('users');
        }

        $username = (string) $request->request->get('username', '');
        $password = (string) $request->request->get('password', '');
        $confirm  = (string) $request->request->get('password_confirm', '');

        if (($error = $this->validateUsername($username)) !== null) {
            $this->addFlash('error', $error);
            return $this->redirectToRoute('users');
        }
        if ($users->findOneByUsername($username) !== null) {
            $this->addFlash('error', 'That username is already taken.');
            return $this->redirectToRoute('users');
        }
        if (($error = $this->validatePassword($password, $confirm)) !== null) {
            $this->addFlash('error', $error);
            return $this->redirectToRoute('users');
        }

        $user = new User($username);
        $user->setRoles($this->capabilityRoles($request));
        $user->setAutoApproveRequests($request->request->getBoolean('auto_approve'));
        $user->setPassword($hasher->hashPassword($user, $password));
        $em->persist($user);
        $em->flush();

        $this->addFlash('success', sprintf('User "%s" created.', $user->getUsername()));
        return $this->redirectToRoute('users');
    }

    #[Route('/users/{id}/update', name: 'users_update', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function update(
        int $id,
        Request $request,
        UserRepository $users,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
    ): Response {
        $user = $users->find($id);
        if ($user === null) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('users_update_' . $id, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('users');
        }

        $username = (string) $request->request->get('username', '');
        if (($error = $this->validateUsername($username)) !== null) {
            $this->addFlash('error', $error);
            return $this->redirectToRoute('users');
        }
        $existing = $users->findOneByUsername($username);
        if ($existing !== null && $existing->getId() !== $user->getId()) {
            $this->addFlash('error', 'That username is already taken.');
            return $this->redirectToRoute('users');
        }

        $password = (string) $request->request->get('password', '');
        if ($password !== '') {
            $confirm = (string) $request->request->get('password_confirm', '');
            if (($error = $this->validatePassword($password, $confirm)) !== null) {
                $this->addFlash('error', $error);
                return $this->redirectToRoute('users');
            }
            $user->setPassword($hasher->hashPassword($user, $password));
        }

        $user->setUsername($username);
        // The master's capabilities are locked to full admin — ignore any submitted toggles.
        if (!$user->isMaster()) {
            $user->setRoles($this->capabilityRoles($request));
        }
        $user->setAutoApproveRequests($request->request->getBoolean('auto_approve'));
        $em->flush();

        $this->addFlash('success', sprintf('User "%s" updated.', $user->getUsername()));
        return $this->redirectToRoute('users');
    }

    #[Route('/users/{id}/delete', name: 'users_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(
        int $id,
        Request $request,
        UserRepository $users,
        EntityManagerInterface $em,
    ): Response {
        $user = $users->find($id);
        if ($user === null) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('users_delete_' . $id, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('users');
        }

        if ($user->isMaster()) {
            $this->addFlash('error', 'The master administrator cannot be deleted.');
            return $this->redirectToRoute('users');
        }
        $current = $this->getUser();
        if ($current instanceof User && $current->getId() === $user->getId()) {
            $this->addFlash('error', 'You cannot delete your own account.');
            return $this->redirectToRoute('users');
        }

        $username = $user->getUsername();
        $em->remove($user);
        $em->flush();

        $this->addFlash('success', sprintf('User "%s" deleted.', $username));
        return $this->redirectToRoute('users');
    }

    /** @return list<string> capability roles selected on the form (master/admin role is never set here) */
    private function capabilityRoles(Request $request): array
    {
        $roles = [];
        if ($request->request->getBoolean('manage_settings')) {
            $roles[] = User::ROLE_MANAGE_SETTINGS;
        }
        if ($request->request->getBoolean('manage_users')) {
            $roles[] = User::ROLE_MANAGE_USERS;
        }
        return $roles;
    }

    private function validateUsername(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return 'Please choose a username.';
        }
        $len = mb_strlen($value);
        if ($len < User::USERNAME_MIN || $len > User::USERNAME_MAX) {
            return sprintf('Username must be between %d and %d characters.', User::USERNAME_MIN, User::USERNAME_MAX);
        }
        if (preg_match(User::USERNAME_PATTERN, mb_strtolower($value)) !== 1) {
            return 'Use letters, digits, dot, dash, or underscore. Must start and end with a letter or digit.';
        }
        return null;
    }

    private function validatePassword(string $password, string $confirm): ?string
    {
        if (strlen($password) < User::PASSWORD_MIN) {
            return sprintf('Password must be at least %d characters.', User::PASSWORD_MIN);
        }
        if ($password !== $confirm) {
            return 'The two passwords do not match.';
        }
        return null;
    }
}
