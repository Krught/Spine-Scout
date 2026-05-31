<?php

declare(strict_types=1);

namespace App\Search\DirectDownload;

use App\Mirror\MirrorList;
use App\Mirror\MirrorListNormalizer;

/**
 * Operator config for the direct-download integration: which indexer kinds are
 * enabled (and in what cascade order), plus the operator-supplied mirror URL
 * list per indexer kind.
 *
 * Stored as JSON inside Integration.options for the Integration row of kind
 * `direct_download`. With no built-in indexer kinds registered, the UI renders
 * an empty state until a protocol adapter is available.
 *
 * Immutable.
 */
final class DirectDownloadConfig
{
    public const DEFAULT_FILENAME_TEMPLATE = '{Author} - {Title} ({Year})';

    /**
     * Default output / watch folder: the in-container path the compose stack
     * always bind-mounts (SPINESCOUT_DOWNLOAD_DIR → /var/www/html/library), so a
     * fresh install can download without first configuring a folder.
     */
    public const DEFAULT_OUTPUT_DIRECTORY = '/var/www/html/library';

    /**
     * Cloudflare-bypass strategy for download pages that challenge a plain HTTP
     * fetch (the slow-partner interstitial returns 403 / a "just a moment" page).
     *
     *  - NONE:     no bypass; the challenged link fails over to the next candidate.
     *  - EXTERNAL: delegate to a FlareSolverr instance, which drives a real
     *              browser through the challenge. The compose stack bundles one
     *              (internal-only, reachable at DEFAULT_FLARESOLVERR_URL), so this
     *              works out of the box; operators can also point at their own.
     *
     * A built-in (in-process) bypasser was removed in favour of FlareSolverr.
     */
    public const BYPASS_NONE     = 'none';
    public const BYPASS_EXTERNAL = 'external';

    /** @var list<string> */
    public const BYPASS_MODES = [self::BYPASS_NONE, self::BYPASS_EXTERNAL];

    /**
     * Default FlareSolverr endpoint: the internal-only service the compose stack
     * ships (service name `flaresolverr`, FlareSolverr's default port 8191).
     * Resolves only inside the compose network; off-stack deploys override it in
     * Settings → Direct downloads.
     */
    public const DEFAULT_FLARESOLVERR_URL = 'http://flaresolverr:8191';

    /**
     * @param list<array{id: string, enabled: bool}> $indexerPriority      Drag-orderable list
     * @param array<string, MirrorList>              $mirrors              Indexer-kind id → mirror list
     * @param bool                                   $fastDownloadEnabled  Include fast-partner (paid) download links
     * @param string                                 $outputDirectory      Absolute path the finished file is moved into
     *                                                                     (the operator's Komga import/watch folder)
     * @param string                                 $filenameTemplate     Naming template with {Author}/{Title}/{Year}/{ISBN}/{Format} tokens
     * @param string                                 $bypassMode           One of self::BYPASS_MODES
     * @param string                                 $bypassFlaresolverrUrl Base URL (host:port) of the FlareSolverr used when $bypassMode is EXTERNAL; defaults to the bundled instance
     */
    public function __construct(
        public readonly array $indexerPriority,
        public readonly array $mirrors,
        public readonly bool $fastDownloadEnabled = false,
        public readonly string $outputDirectory = self::DEFAULT_OUTPUT_DIRECTORY,
        public readonly string $filenameTemplate = self::DEFAULT_FILENAME_TEMPLATE,
        public readonly string $bypassMode = self::BYPASS_EXTERNAL,
        public readonly string $bypassFlaresolverrUrl = self::DEFAULT_FLARESOLVERR_URL,
    ) {
    }

    public static function default(): self
    {
        return new self([], [], false, self::DEFAULT_OUTPUT_DIRECTORY, self::DEFAULT_FILENAME_TEMPLATE);
    }

    /**
     * @param array<string, mixed>|null $raw      JSON-decoded options blob
     */
    public static function fromArray(?array $raw, MirrorListNormalizer $normalizer): self
    {
        if ($raw === null) {
            return self::default();
        }

        $priority = [];
        $rawPriority = $raw['indexerPriority'] ?? null;
        if (is_array($rawPriority)) {
            $seen = [];
            foreach ($rawPriority as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $id = $item['id'] ?? null;
                if (!is_string($id) || $id === '' || isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $priority[] = [
                    'id'      => $id,
                    'enabled' => (bool) ($item['enabled'] ?? true),
                ];
            }
        }

        $mirrors = [];
        $rawMirrors = $raw['mirrors'] ?? null;
        if (is_array($rawMirrors)) {
            foreach ($rawMirrors as $kind => $value) {
                if (!is_string($kind) || $kind === '') {
                    continue;
                }
                $mirrors[$kind] = MirrorList::fromJsonValue($value, $normalizer);
            }
        }

        $template = trim((string) ($raw['filenameTemplate'] ?? ''));
        $output = trim((string) ($raw['outputDirectory'] ?? ''));

        // Default to EXTERNAL (the bundled FlareSolverr). Stored configs from the
        // retired INTERNAL mode are unknown values now and fall back here too.
        $bypassMode = (string) ($raw['bypassMode'] ?? self::BYPASS_EXTERNAL);
        if (!in_array($bypassMode, self::BYPASS_MODES, true)) {
            $bypassMode = self::BYPASS_EXTERNAL;
        }

        // A blank FlareSolverr address resolves to the bundled instance so a
        // fresh install works without configuring anything.
        $flaresolverrUrl = trim((string) ($raw['bypassFlaresolverrUrl'] ?? ''));
        if ($flaresolverrUrl === '') {
            $flaresolverrUrl = self::DEFAULT_FLARESOLVERR_URL;
        }

        return new self(
            $priority,
            $mirrors,
            (bool) ($raw['fastDownloadEnabled'] ?? false),
            $output !== '' ? $output : self::DEFAULT_OUTPUT_DIRECTORY,
            $template !== '' ? $template : self::DEFAULT_FILENAME_TEMPLATE,
            $bypassMode,
            $flaresolverrUrl,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $mirrors = [];
        foreach ($this->mirrors as $kind => $list) {
            $mirrors[$kind] = $list->toArray();
        }
        return [
            'indexerPriority'     => $this->indexerPriority,
            'mirrors'             => $mirrors,
            'fastDownloadEnabled' => $this->fastDownloadEnabled,
            'outputDirectory'     => $this->outputDirectory,
            'filenameTemplate'    => $this->filenameTemplate,
            'bypassMode'          => $this->bypassMode,
            'bypassFlaresolverrUrl' => $this->bypassFlaresolverrUrl,
        ];
    }

    public function mirrorsFor(string $kind): MirrorList
    {
        return $this->mirrors[$kind] ?? MirrorList::empty();
    }

    public function isIndexerEnabled(string $kind): bool
    {
        foreach ($this->indexerPriority as $row) {
            if ($row['id'] === $kind) {
                return $row['enabled'];
            }
        }
        return false;
    }
}
