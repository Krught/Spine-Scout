<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class UsersController extends AbstractController
{
    #[Route('/users', name: 'users', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('users/index.html.twig');
    }
}
