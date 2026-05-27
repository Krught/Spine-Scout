<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Integration;
use App\Entity\User;
use App\Message\RefreshHardcoverTrending;
use App\Message\SyncGrimmoryLibrary;
use App\Repository\IntegrationRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * First-run wizard. Step 1 captures a username into the session; step 2
 * uses it to create a ROLE_ADMIN user and log them in. Both routes 404
 * once any user exists.
 */
final class SetupController extends AbstractController
{
    private const SESSION_USERNAME_KEY   = 'setup.username';
    private const SESSION_ONBOARDING_KEY = 'setup.onboarding';
    private const SESSION_LOADING_KEY    = 'setup.loading';
    private const USERNAME_TOKEN_ID      = 'setup_username';
    private const PASSWORD_TOKEN_ID      = 'setup_password';
    private const HARDCOVER_TOKEN_ID     = 'setup_hardcover';
    private const KOMGA_TOKEN_ID         = 'setup_komga';
    private const TOTAL_STEPS            = 4;
    private const HARDCOVER_DOCS_URL     = 'https://hardcover.app/account/api';

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
            'total'      => self::TOTAL_STEPS,
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
                $session->set(self::SESSION_ONBOARDING_KEY, true);
                $security->login($user);
                $this->addFlash('success', 'Welcome to Spine Scout. Your administrator account is ready.');

                return $this->redirectToRoute('app_setup_hardcover');
            }
        }

        return $this->render('setup/password.html.twig', [
            'username'   => $username,
            'error'      => $error,
            'csrf_token' => self::PASSWORD_TOKEN_ID,
            'step'       => 2,
            'total'      => self::TOTAL_STEPS,
        ]);
    }

    #[Route('/setup/hardcover', name: 'app_setup_hardcover', methods: ['GET', 'POST'])]
    public function hardcover(
        Request $request,
        IntegrationRepository $integrations,
        EntityManagerInterface $em,
        MessageBusInterface $bus,
    ): Response {
        if (!$this->isOnboarding($request)) {
            return $this->redirectToRoute('home');
        }

        $error = null;
        $token = '';

        if ($request->isMethod('POST')) {
            $csrf = (string) $request->request->get('_csrf_token', '');
            if (!$this->isCsrfTokenValid(self::HARDCOVER_TOKEN_ID, $csrf)) {
                $error = 'Your session expired. Please try again.';
            } elseif ($request->request->get('skip') !== null) {
                return $this->redirectToRoute('app_setup_komga');
            } else {
                $token = trim((string) $request->request->get('token', ''));
                if ($token === '') {
                    $error = 'Enter an API token, or click Skip to set this up later.';
                } else {
                    $integration = $integrations->getOrCreate(Integration::KIND_HARDCOVER);
                    $integration->setAuthType(Integration::AUTH_API_KEY);
                    $integration->setCredentials(['token' => $token]);
                    $integration->setEnabled(true);
                    $em->persist($integration);
                    $em->flush();
                    $bus->dispatch(new RefreshHardcoverTrending(force: true));
                    $this->addFlash('success', 'Hardcover enabled. Fetching metadata in the background.');
                    return $this->redirectToRoute('app_setup_komga');
                }
            }
        }

        return $this->render('setup/hardcover.html.twig', [
            'token'           => $token,
            'error'           => $error,
            'csrf_token'      => self::HARDCOVER_TOKEN_ID,
            'docs_url'        => self::HARDCOVER_DOCS_URL,
            'step'            => 3,
            'total'           => self::TOTAL_STEPS,
        ]);
    }

    #[Route('/setup/komga', name: 'app_setup_komga', methods: ['GET', 'POST'])]
    public function komga(
        Request $request,
        IntegrationRepository $integrations,
        EntityManagerInterface $em,
        MessageBusInterface $bus,
    ): Response {
        if (!$this->isOnboarding($request)) {
            return $this->redirectToRoute('home');
        }

        $session = $request->getSession();
        $error    = null;
        $baseUrl  = '';
        $username = '';

        if ($request->isMethod('POST')) {
            $csrf = (string) $request->request->get('_csrf_token', '');
            if (!$this->isCsrfTokenValid(self::KOMGA_TOKEN_ID, $csrf)) {
                $error = 'Your session expired. Please try again.';
            } elseif ($request->request->get('skip') !== null) {
                $session->remove(self::SESSION_ONBOARDING_KEY);
                return $this->redirectToRoute('home');
            } else {
                $baseUrl  = trim((string) $request->request->get('base_url', ''));
                $username = trim((string) $request->request->get('username', ''));
                $password = (string) $request->request->get('password', '');

                if ($baseUrl === '' || $username === '' || $password === '') {
                    $error = 'Enter your Komga server URL, username, and password.';
                } elseif (filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
                    $error = 'Enter a valid Komga server URL (including http:// or https://).';
                } else {
                    $integration = $integrations->getOrCreate(Integration::KIND_GRIMMORY);
                    $integration->setBaseUrl($baseUrl);
                    $integration->setAuthType(Integration::AUTH_BASIC);
                    $integration->setCredentials(['username' => $username, 'password' => $password]);
                    $integration->setEnabled(true);
                    $em->persist($integration);
                    $em->flush();
                    $bus->dispatch(new SyncGrimmoryLibrary(force: true));
                    $session->remove(self::SESSION_ONBOARDING_KEY);
                    $session->set(self::SESSION_LOADING_KEY, true);
                    $this->addFlash('success', 'Komga connected. Syncing your library in the background.');
                    return $this->redirectToRoute('app_setup_loading');
                }
            }
        }

        return $this->render('setup/komga.html.twig', [
            'base_url'   => $baseUrl,
            'username'   => $username,
            'error'      => $error,
            'csrf_token' => self::KOMGA_TOKEN_ID,
            'step'       => 4,
            'total'      => self::TOTAL_STEPS,
        ]);
    }

    #[Route('/setup/loading', name: 'app_setup_loading', methods: ['GET'])]
    public function loading(Request $request): Response
    {
        $session = $request->getSession();
        if (!$session->get(self::SESSION_LOADING_KEY, false)) {
            return $this->redirectToRoute('home');
        }
        $session->remove(self::SESSION_LOADING_KEY);

        return $this->render('setup/loading.html.twig', [
            'redirect_url' => $this->generateUrl('home'),
            'delay_ms'     => 5000,
        ]);
    }

    #[Route('/_dev/setup-loading', name: 'app_dev_setup_loading', methods: ['GET'])]
    public function loadingPreview(\Symfony\Component\HttpKernel\KernelInterface $kernel): Response
    {
        if ($kernel->getEnvironment() !== 'dev') {
            throw new NotFoundHttpException();
        }

        return $this->render('setup/loading.html.twig', [
            // No redirect in preview mode — animation loops so it can be inspected.
            'redirect_url' => null,
            'delay_ms'     => 5000,
        ]);
    }

    private function isOnboarding(Request $request): bool
    {
        return $this->isGranted(User::ROLE_ADMIN)
            && (bool) $request->getSession()->get(self::SESSION_ONBOARDING_KEY, false);
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
