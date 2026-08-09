<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\FreeleechItemRepository;
use App\Service\CoverCache;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CoverController extends AbstractController
{
    /** Must match the `internal` alias location in docker/nginx/default.conf. */
    private const ACCEL_PREFIX = '/_covers/';

    #[Route('/cache/cover/{hash}', name: 'cover_proxy', requirements: ['hash' => '[a-f0-9]{40}'], methods: ['GET'])]
    public function show(string $hash, Request $request, CoverCache $covers): Response
    {
        $resolved = $covers->resolve($hash);
        if ($resolved === null) {
            return $this->miss();
        }

        return $this->serve($hash, $resolved, $request);
    }

    /**
     * Cover for a freeleech item Hardcover could not resolve: MAM's own thumbnail, fetched
     * once and thereafter served off our disk cache, so no browser ever hotlinks the tracker.
     */
    #[Route('/cache/freeleech-cover/{id}', name: 'freeleech_cover', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function freeleechCover(
        int $id,
        Request $request,
        CoverCache $covers,
        FreeleechItemRepository $items,
    ): Response {
        $item = $items->find($id);
        if ($item === null || $item->getThumbnailUrl() === null) {
            return $this->miss();
        }
        $resolved = $covers->fetchMamThumbnail($item);
        if ($resolved === null) {
            return $this->miss();
        }

        return $this->serve($resolved['hash'], $resolved, $request);
    }

    /**
     * Misses come in storms while an upstream is down (e.g. Komga 409ing every
     * thumbnail): a short public max-age lets the browser absorb repeat requests
     * for the same missing cover without hiding a recovered one for long. Keep
     * this short — long-lived negative caching would pin placeholders client-side.
     */
    private function miss(): Response
    {
        $response = new Response('', Response::HTTP_NOT_FOUND);
        $response->setPublic();
        $response->setMaxAge(300);
        return $response;
    }

    /** @param array{path: string, contentType: string} $resolved */
    private function serve(string $hash, array $resolved, Request $request): Response
    {
        $response = new Response();
        $response->headers->set('Content-Type', $resolved['contentType']);
        $response->setPublic();
        $response->setMaxAge(86400 * 30);
        $response->headers->addCacheControlDirective('immutable');
        $response->headers->set('X-Cache-Source', 'spinescout-cover-cache');
        $response->headers->set('Content-Disposition', 'inline');

        // Validators are derived from the file on disk so conditional requests can be
        // answered with a 304 before we ever hand off to nginx. The ETag deliberately
        // reproduces nginx's own "<hex mtime>-<hex size>" format: nginx drops upstream
        // ETags across an X-Accel-Redirect and stamps its own on the 200, so this is
        // the value the browser sends back in If-None-Match.
        $stat = @stat($resolved['path']);
        if (is_array($stat)) {
            $response->setEtag(sprintf('%x-%x', $stat['mtime'], $stat['size']));
            $response->setLastModified(new \DateTimeImmutable('@' . $stat['mtime']));
            if ($response->isNotModified($request)) {
                return $response;
            }
        }

        // Hand the bytes off to nginx: the worker is released immediately instead of
        // streaming the file itself. The cache is sharded by the hash's first two
        // characters (see CoverCache::shard()).
        $response->headers->set(
            'X-Accel-Redirect',
            self::ACCEL_PREFIX . rawurlencode(substr($hash, 0, 2)) . '/' . rawurlencode($hash) . '.webp',
        );

        return $response;
    }
}
