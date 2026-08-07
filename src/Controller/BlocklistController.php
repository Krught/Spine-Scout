<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\BlockedReleaseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Settings → Blocklist: the per-book releases the pipeline blocked after a
 * failure that proved the release itself was bad. Read-only apart from unblock
 * (drop one entry so the next sweep may retry it) and clearing expired rows.
 */
#[Route('/settings/blocklist')]
#[IsGranted('ROLE_MANAGE_SETTINGS')]
final class BlocklistController extends AbstractController
{
    #[Route('', name: 'settings_blocklist', methods: ['GET'])]
    public function index(BlockedReleaseRepository $blockedReleases): Response
    {
        return $this->render('settings/blocklist.html.twig', [
            'active_tab' => 'blocklist',
            'blocks' => $blockedReleases->findAllForList(),
            'now' => new \DateTimeImmutable(),
        ]);
    }

    #[Route('/{id}/unblock', name: 'settings_blocklist_unblock', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function unblock(
        int $id,
        Request $request,
        BlockedReleaseRepository $blockedReleases,
        EntityManagerInterface $em,
    ): Response {
        if (!$this->isCsrfTokenValid('settings_blocklist_unblock', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');

            return $this->redirectToRoute('settings_blocklist');
        }

        $block = $blockedReleases->find($id);
        if ($block === null) {
            $this->addFlash('error', 'Blocklist entry not found.');

            return $this->redirectToRoute('settings_blocklist');
        }

        $em->remove($block);
        $em->flush();

        $this->addFlash('success', 'Release unblocked.');

        return $this->redirectToRoute('settings_blocklist');
    }

    #[Route('/clear-expired', name: 'settings_blocklist_clear_expired', methods: ['POST'])]
    public function clearExpired(Request $request, BlockedReleaseRepository $blockedReleases): Response
    {
        if (!$this->isCsrfTokenValid('settings_blocklist_clear_expired', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');

            return $this->redirectToRoute('settings_blocklist');
        }

        $removed = $blockedReleases->purgeExpired();
        $this->addFlash('success', sprintf('Removed %d expired block(s).', $removed));

        return $this->redirectToRoute('settings_blocklist');
    }
}
