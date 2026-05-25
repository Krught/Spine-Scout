<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * First-run wizard. Step 1 captures a username into the session; step 2
 * uses it to create a ROLE_ADMIN user and log them in. Both routes 404
 * once any user exists.
 */
final class SetupController extends AbstractController
{
    private const SESSION_USERNAME_KEY = 'setup.username';
    private const USERNAME_TOKEN_ID    = 'setup_username';
    private const PASSWORD_TOKEN_ID    = 'setup_password';

    #[Route('/setup', name: 'app_setup', methods: ['GET', 'POST'])]
    public function username(Request $request, UserRepository $users): Response
    {
        $this->guardNoUsers($users);

        $session = $request->getSession();
        $value = (string) $session->get(self::SESSION_USERNAME_KEY, '');
        $error = null;

        if ($request->isMethod('POST')) {
            $value = trim((string) $request->request->get('username', ''));
            $token = (string) $request->request->get('_csrf_token', '');

            if (!$this->isCsrfTokenValid(self::USERNAME_TOKEN_ID, $token)) {
                $error = 'Your session expired. Please try again.';
            } else {
                $error = $this->validateUsername($value);
                if ($error === null) {
                    $session->set(self::SESSION_USERNAME_KEY, User::normalizeUsername($value));
                    return $this->redirectToRoute('app_setup_password');
                }
            }
        }

        return $this->render('setup/username.html.twig', [
            'username'   => $value,
            'error'      => $error,
            'csrf_token' => self::USERNAME_TOKEN_ID,
            'step'       => 1,
            'total'      => 2,
        ]);
    }

    #[Route('/setup/password', name: 'app_setup_password', methods: ['GET', 'POST'])]
    public function password(
        Request $request,
        UserRepository $users,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
        Security $security,
    ): Response {
        $this->guardNoUsers($users);

        $session = $request->getSession();
        $username = (string) $session->get(self::SESSION_USERNAME_KEY, '');
        if ($username === '') {
            return $this->redirectToRoute('app_setup');
        }

        $error = null;

        if ($request->isMethod('POST')) {
            $token = (string) $request->request->get('_csrf_token', '');
            $password = (string) $request->request->get('password', '');
            $confirm  = (string) $request->request->get('password_confirm', '');

            if (!$this->isCsrfTokenValid(self::PASSWORD_TOKEN_ID, $token)) {
                $error = 'Your session expired. Please try again.';
            } elseif (strlen($password) < 8) {
                $error = 'Password must be at least 8 characters.';
            } elseif ($password !== $confirm) {
                $error = 'The two passwords do not match.';
            } else {
                $user = new User($username);
                $user->setRoles([User::ROLE_ADMIN]);
                $user->setPassword($hasher->hashPassword($user, $password));
                $em->persist($user);
                $em->flush();

                $session->remove(self::SESSION_USERNAME_KEY);
                $security->login($user);
                $this->addFlash('success', 'Welcome to Spine Scout. Your administrator account is ready.');

                return $this->redirectToRoute('home');
            }
        }

        return $this->render('setup/password.html.twig', [
            'username'   => $username,
            'error'      => $error,
            'csrf_token' => self::PASSWORD_TOKEN_ID,
            'step'       => 2,
            'total'      => 2,
        ]);
    }

    private function guardNoUsers(UserRepository $users): void
    {
        if ($users->hasAny()) {
            throw new NotFoundHttpException();
        }
    }

    private function validateUsername(string $value): ?string
    {
        if ($value === '') {
            return 'Please choose a username.';
        }
        $len = mb_strlen($value);
        if ($len < User::USERNAME_MIN || $len > User::USERNAME_MAX) {
            return sprintf(
                'Username must be between %d and %d characters.',
                User::USERNAME_MIN,
                User::USERNAME_MAX,
            );
        }
        if (preg_match(User::USERNAME_PATTERN, mb_strtolower($value)) !== 1) {
            return 'Use letters, digits, dot, dash, or underscore. Must start and end with a letter or digit.';
        }
        return null;
    }
}
