<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The signed-in user's own account page: read-only account facts plus a
 * self-service password change. Anything beyond one's own password (username,
 * capabilities) stays admin-managed on the Users page.
 */
#[IsGranted('ROLE_USER')]
final class ProfileController extends AbstractController
{
    /** CSRF id for the password-change form. */
    private const PASSWORD_CSRF_ID = 'profile_password';

    #[Route('/profile', name: 'profile', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('profile/index.html.twig');
    }

    #[Route('/profile/password', name: 'profile_password', methods: ['POST'])]
    public function changePassword(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
    ): Response {
        if (!$this->isCsrfTokenValid(self::PASSWORD_CSRF_ID, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('profile');
        }

        /** @var User $user */
        $user = $this->getUser();

        $current  = (string) $request->request->get('current_password', '');
        $password = (string) $request->request->get('password', '');
        $confirm  = (string) $request->request->get('password_confirm', '');

        if ($current === '' || $password === '' || $confirm === '') {
            $this->addFlash('error', 'Please fill in all three password fields.');
            return $this->redirectToRoute('profile');
        }
        if (!$hasher->isPasswordValid($user, $current)) {
            $this->addFlash('error', 'Your current password is incorrect.');
            return $this->redirectToRoute('profile');
        }
        if (strlen($password) < User::PASSWORD_MIN) {
            $this->addFlash('error', sprintf('Password must be at least %d characters.', User::PASSWORD_MIN));
            return $this->redirectToRoute('profile');
        }
        if ($password !== $confirm) {
            $this->addFlash('error', 'The two passwords do not match.');
            return $this->redirectToRoute('profile');
        }

        $user->setPassword($hasher->hashPassword($user, $password));
        $em->flush();

        $this->addFlash('success', 'Your password has been changed.');
        return $this->redirectToRoute('profile');
    }
}
