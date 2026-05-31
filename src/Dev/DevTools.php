<?php

declare(strict_types=1);

namespace App\Dev;

/**
 * Gate for developer-only tooling (the Settings → Development panel and its
 * manual test harnesses).
 *
 * Available only when BOTH hold:
 *   1. The app is NOT running in the `prod` environment, and
 *   2. the current git branch is not the production branch (default `main`).
 *
 * The environment check is the hard guarantee — it is reliable even when no
 * `.git` is shipped (containers usually strip it). The branch check is a
 * best-effort extra: if the branch can't be determined it does not block, so a
 * dev environment without `.git` still shows the panel.
 */
final class DevTools
{
    public function __construct(
        private readonly string $environment,
        private readonly string $projectDir,
        private readonly string $productionBranch = 'main',
    ) {
    }

    public function isAvailable(): bool
    {
        if ($this->environment === 'prod') {
            return false;
        }

        $branch = $this->currentBranch();

        return $branch === null || $branch !== $this->productionBranch;
    }

    public function environment(): string
    {
        return $this->environment;
    }

    /** Current git branch name, short SHA if detached, or null if undeterminable. */
    public function currentBranch(): ?string
    {
        $head = $this->projectDir . '/.git/HEAD';
        if (!is_file($head)) {
            return null;
        }
        $content = trim((string) @file_get_contents($head));
        if ($content === '') {
            return null;
        }
        if (str_starts_with($content, 'ref:')) {
            return preg_replace('#^refs/heads/#', '', trim(substr($content, 4)));
        }

        return substr($content, 0, 12);
    }
}
