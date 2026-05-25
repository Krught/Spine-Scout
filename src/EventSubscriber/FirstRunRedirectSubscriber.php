<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Repository\UserRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Redirects every request to /setup while the users table is empty, so a
 * fresh install always lands on the first-run wizard.
 */
final class FirstRunRedirectSubscriber implements EventSubscriberInterface
{
    private const ALLOW_PREFIXES = [
        '/setup',
        '/_wdt',
        '/_profiler',
        '/_error',
        '/assets',
        '/build',
        '/healthz',
    ];

    private ?bool $cached = null;

    public function __construct(
        private readonly UserRepository $users,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', 32]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $path = $event->getRequest()->getPathInfo();
        foreach (self::ALLOW_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return;
            }
        }

        if ($this->hasAnyUserCached()) {
            return;
        }

        $event->setResponse(new RedirectResponse(
            $this->urls->generate('app_setup'),
        ));
    }

    private function hasAnyUserCached(): bool
    {
        return $this->cached ??= $this->users->hasAny();
    }
}
