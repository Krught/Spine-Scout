<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\CoverCache;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

final class CoverController extends AbstractController
{
    #[Route('/cache/cover/{hash}', name: 'cover_proxy', requirements: ['hash' => '[a-f0-9]{40}'], methods: ['GET'])]
    public function show(string $hash, CoverCache $covers): Response
    {
        $resolved = $covers->resolve($hash);
        if ($resolved === null) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }
        $response = new BinaryFileResponse($resolved['path']);
        $response->headers->set('Content-Type', $resolved['contentType']);
        $response->setPublic();
        $response->setMaxAge(86400 * 30);
        $response->headers->set('X-Cache-Source', 'spinescout-cover-cache');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE);
        return $response;
    }
}
