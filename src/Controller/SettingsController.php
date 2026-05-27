<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Book;
use App\Entity\Integration;
use App\Form\GrimmoryIntegrationType;
use App\Form\HardcoverIntegrationType;
use App\Form\OpenLibraryIntegrationType;
use App\Message\RefreshHardcoverTrending;
use App\Message\RefreshOpenLibraryTrending;
use App\Message\SyncGrimmoryLibrary;
use App\Repository\BookRepository;
use App\Repository\IntegrationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/settings', name: 'settings_')]
#[IsGranted('ROLE_ADMIN')]
final class SettingsController extends AbstractController
{
    #[Route('', name: 'index')]
    public function index(): Response
    {
        return $this->redirectToRoute('settings_general');
    }

    #[Route('/general', name: 'general')]
    public function general(): Response
    {
        return $this->render('settings/general.html.twig', [
            'active_tab' => 'general',
        ]);
    }

    #[Route('/users', name: 'users')]
    public function users(): Response
    {
        return $this->render('settings/users.html.twig', [
            'active_tab' => 'users',
        ]);
    }

    #[Route('/grimmory', name: 'grimmory')]
    public function grimmory(
        Request $request,
        IntegrationRepository $repository,
        BookRepository $bookRepository,
        EntityManagerInterface $em,
    ): Response {
        $integration = $repository->getOrCreate(Integration::KIND_GRIMMORY);

        $form = $this->createForm(GrimmoryIntegrationType::class, $integration, [
            'has_existing_credentials' => $integration->hasCredentials(),
            'discovered_libraries' => $integration->getDiscoveredLibraries(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Explicit in case an older row predates the AUTH_BASIC default.
            $integration->setAuthType(Integration::AUTH_BASIC);
            $this->applyCredentials($form, $integration);
            $integration->setEnabled(
                $integration->getBaseUrl() !== null
                && $integration->getBaseUrl() !== ''
                && $integration->hasCredentials(),
            );

            if ($integration->getId() === null) {
                $em->persist($integration);
            } else {
                $integration->touch();
            }
            $em->flush();

            $this->addFlash('success', 'Grimmory settings saved.');

            return $this->redirectToRoute('settings_grimmory');
        }

        $libraryCount = $integration->getId() !== null
            ? $bookRepository->countActiveBySource(Book::SOURCE_GRIMMORY)
            : 0;

        $recentBooks = $integration->getId() !== null
            ? $bookRepository->findBy(
                ['source' => Book::SOURCE_GRIMMORY, 'removedAt' => null],
                ['lastSeenAt' => 'DESC'],
                25,
            )
            : [];

        return $this->render('settings/grimmory.html.twig', [
            'active_tab' => 'grimmory',
            'form' => $form,
            'integration' => $integration,
            'library_count' => $libraryCount,
            'recent_books' => $recentBooks,
        ]);
    }

    #[Route('/grimmory/sync', name: 'grimmory_sync', methods: ['POST'])]
    public function grimmorySync(
        Request $request,
        IntegrationRepository $repository,
        MessageBusInterface $bus,
    ): Response {
        if (!$this->isCsrfTokenValid('grimmory_sync', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('settings_grimmory');
        }

        $integration = $repository->findByKind(Integration::KIND_GRIMMORY);
        if ($integration === null || !$integration->isEnabled()) {
            $this->addFlash('error', 'Grimmory integration is not enabled.');
            return $this->redirectToRoute('settings_grimmory');
        }

        $bus->dispatch(new SyncGrimmoryLibrary(force: true));
        $this->addFlash('success', 'Sync queued. Refresh in a moment to see results.');

        return $this->redirectToRoute('settings_grimmory');
    }

    #[Route('/metadata', name: 'metadata')]
    public function metadata(
        Request $request,
        IntegrationRepository $repository,
        EntityManagerInterface $em,
    ): Response {
        $hardcover  = $repository->getOrCreate(Integration::KIND_HARDCOVER);
        $openLibrary = $repository->getOrCreate(Integration::KIND_OPENLIBRARY);

        $this->seedDefaults($hardcover, $openLibrary);

        $hardcoverForm = $this->createForm(HardcoverIntegrationType::class, $hardcover, [
            'existing_token' => (string) ($hardcover->getCredentials()['token'] ?? ''),
            'edition_preferences' => $hardcover->getHardcoverEditionPreferences(),
        ]);
        $openLibraryForm = $this->createForm(OpenLibraryIntegrationType::class, $openLibrary);

        $hardcoverForm->handleRequest($request);
        if ($hardcoverForm->isSubmitted() && $hardcoverForm->isValid()) {
            $hardcover->setAuthType(Integration::AUTH_API_KEY);
            $this->applyHardcoverToken($hardcoverForm, $hardcover);
            $this->applyHardcoverEditionPrefs($hardcoverForm, $hardcover);
            // Can't be enabled without a token; clamp here so the UI doesn't lie about state.
            if ($hardcover->isEnabled() && !$hardcover->hasCredentials()) {
                $hardcover->setEnabled(false);
                $this->addFlash('error', 'Hardcover needs an API token before it can be enabled.');
            }
            $this->persistOrTouch($em, $hardcover);
            $em->flush();
            $this->addFlash('success', 'Hardcover settings saved.');
            return $this->redirectToRoute('settings_metadata');
        }

        $openLibraryForm->handleRequest($request);
        if ($openLibraryForm->isSubmitted() && $openLibraryForm->isValid()) {
            $openLibrary->setAuthType(Integration::AUTH_NONE);
            $this->persistOrTouch($em, $openLibrary);
            $em->flush();
            $this->addFlash('success', 'Open Library settings saved.');
            return $this->redirectToRoute('settings_metadata');
        }

        return $this->render('settings/metadata.html.twig', [
            'active_tab' => 'metadata',
            'hardcover_form' => $hardcoverForm,
            'openlibrary_form' => $openLibraryForm,
            'hardcover' => $hardcover,
            'openlibrary' => $openLibrary,
        ]);
    }

    #[Route('/metadata/hardcover/refresh', name: 'metadata_hardcover_refresh', methods: ['POST'])]
    public function refreshHardcover(Request $request, MessageBusInterface $bus): Response
    {
        if (!$this->isCsrfTokenValid('metadata_hardcover_refresh', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('settings_metadata');
        }
        $bus->dispatch(new RefreshHardcoverTrending(force: true));
        $this->addFlash('success', 'Hardcover refresh queued.');
        return $this->redirectToRoute('settings_metadata');
    }

    #[Route('/metadata/openlibrary/refresh', name: 'metadata_openlibrary_refresh', methods: ['POST'])]
    public function refreshOpenLibrary(Request $request, MessageBusInterface $bus): Response
    {
        if (!$this->isCsrfTokenValid('metadata_openlibrary_refresh', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('settings_metadata');
        }
        $bus->dispatch(new RefreshOpenLibraryTrending(force: true));
        $this->addFlash('success', 'Open Library refresh queued.');
        return $this->redirectToRoute('settings_metadata');
    }

    private function seedDefaults(Integration $hardcover, Integration $openLibrary): void
    {
        if ($hardcover->getId() === null) {
            $hardcover->setAuthType(Integration::AUTH_API_KEY);
            $hardcover->setSyncIntervalMinutes(60);
        }
        if ($openLibrary->getId() === null) {
            $openLibrary->setAuthType(Integration::AUTH_NONE);
            $openLibrary->setSyncIntervalMinutes(60);
        }
    }

    private function persistOrTouch(EntityManagerInterface $em, Integration $integration): void
    {
        if ($integration->getId() === null) {
            $em->persist($integration);
        } else {
            $integration->touch();
        }
    }

    private function applyHardcoverToken($form, Integration $integration): void
    {
        $existing = $integration->getCredentials();
        $token = (string) $form->get('apiToken')->getData();
        $next = ['token' => $token !== '' ? $token : ($existing['token'] ?? null)];
        $integration->setCredentials(array_filter($next, static fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * Normalizes CSV inputs to match Hardcover's casing: languages/countries lowercased
     * for `code3`/`code2`, formats title-cased for `physical_format`.
     */
    private function applyHardcoverEditionPrefs($form, Integration $integration): void
    {
        $parseCsv = static function (mixed $raw, string $case): array {
            if (!is_string($raw)) {
                return [];
            }
            $parts = array_map('trim', explode(',', $raw));
            $parts = array_filter($parts, static fn ($v) => $v !== '');
            return array_values(array_map(static fn (string $v) => match ($case) {
                'lower' => strtolower($v),
                'upper' => strtoupper($v),
                'title' => ucwords(strtolower($v)),
                default => $v,
            }, $parts));
        };
        $integration->setHardcoverEditionPreferences([
            'languages' => $parseCsv($form->get('preferredLanguages')->getData(), 'lower'),
            'formats'   => $parseCsv($form->get('preferredFormats')->getData(),   'title'),
            'countries' => $parseCsv($form->get('preferredCountries')->getData(), 'upper'),
        ]);
    }

    private function applyCredentials($form, Integration $integration): void
    {
        $existing = $integration->getCredentials();
        $username = (string) $form->get('username')->getData();
        $password = (string) $form->get('password')->getData();

        $next = [
            'username' => $username !== '' ? $username : ($existing['username'] ?? null),
            'password' => $password !== '' ? $password : ($existing['password'] ?? null),
        ];

        $integration->setCredentials(array_filter($next, static fn ($v) => $v !== null && $v !== ''));
    }
}
