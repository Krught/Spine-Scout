<?php

declare(strict_types=1);

namespace App\Search\Source\Http;

use App\Search\DirectDownload\DirectDownloadConfig;
use App\Search\SearchSettingsProvider;
use App\Search\Source\ReleaseCandidate;
use App\Search\Source\ReleaseSearchPlan;
use App\Search\Source\ReleaseSourceInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Shared base for the live HTTP "getter" release sources that scrape an
 * operator-supplied HTML index: read DirectDownloadConfig, take the mirror list
 * for this source's id, and cascade across the mirrors on failure — building a
 * search URL, GETting it, and mapping the parsed rows onto ReleaseCandidates.
 *
 * Subclasses provide the brand identity (getName/sourceId/getDisplayName), the
 * per-site search-URL builder, the results→candidate mapping, and the
 * per-candidate detail/download-link resolution (resolveDetail, from the
 * interface). Everything I/O- and config-shaped lives here so each concrete
 * source stays small and fixture-testable.
 *
 * Never throws on transport/parse failure — a dead or challenged mirror yields
 * nothing so the cascade (and the evaluator's source failover) move on, matching
 * the project ground rule.
 *
 * (DirectHttpSource — the original Anna's Archive source — predates this base and
 * is intentionally left standalone to avoid destabilising the proven path.)
 */
abstract class AbstractDirectHttpSource implements ReleaseSourceInterface
{
    protected const TIMEOUT = 30;
    protected const MAX_REDIRECTS = 5;
    protected const HEADERS = [
        'User-Agent' => 'Mozilla/5.0 (compatible; SpineScout/1.0)',
        'Accept'     => 'text/html',
    ];

    public function __construct(
        protected readonly SearchSettingsProvider $settings,
        protected readonly HttpClientInterface $httpClient,
    ) {
    }

    abstract public function getName(): string;

    abstract public function sourceId(): string;

    abstract public function getDisplayName(): string;

    public function isAvailable(): bool
    {
        return $this->getUnavailableReason() === null;
    }

    public function getUnavailableReason(): ?string
    {
        $config = $this->settings->getDirectDownloadConfig();
        $id = $this->sourceId();

        if (!$config->isIndexerEnabled($id)) {
            return sprintf('Enable the %s source in Settings → Direct downloads.', $this->getDisplayName());
        }
        if ($config->mirrorsFor($id)->toArray() === []) {
            return sprintf('Add at least one %s mirror in Settings → Direct downloads.', $this->getDisplayName());
        }

        return null;
    }

    /**
     * Cascade the configured mirrors: the first that returns a non-empty set of
     * candidates wins; transport errors, "no results", and challenge/landing
     * pages all fall through to the next mirror.
     *
     * @return list<ReleaseCandidate>
     */
    public function search(ReleaseSearchPlan $plan, ?DirectDownloadConfig $config = null): array
    {
        foreach ($this->searchMirrors($config) as $base) {
            $candidates = $this->searchVia($base, $plan, $config);
            if ($candidates !== []) {
                return $candidates;
            }
        }

        return [];
    }

    public function searchVia(string $mirror, ReleaseSearchPlan $plan, ?DirectDownloadConfig $config = null): array
    {
        $response = $this->request($this->buildSearchUrl($mirror, $plan));
        if ($response['error'] !== null || $response['html'] === '') {
            return [];
        }

        return $this->parseToCandidates($mirror, $response['html']);
    }

    public function searchUrlFor(string $mirror, ReleaseSearchPlan $plan): string
    {
        return $this->buildSearchUrl($mirror, $plan);
    }

    public function searchPlanUrl(ReleaseSearchPlan $plan, ?DirectDownloadConfig $config = null): array
    {
        $mirrors = $this->searchMirrors($config);
        if ($mirrors === []) {
            return ['mirror' => null, 'url' => null];
        }
        $base = $mirrors[0];

        return ['mirror' => $base, 'url' => $this->searchUrlFor($base, $plan)];
    }

    /**
     * Ordered mirrors for this source, or [] when disabled / none configured.
     *
     * @return list<string>
     */
    protected function searchMirrors(?DirectDownloadConfig $config = null): array
    {
        $config ??= $this->settings->getDirectDownloadConfig();
        $id = $this->sourceId();
        if (!$config->isIndexerEnabled($id)) {
            return [];
        }

        return $config->mirrorsFor($id)->toArray();
    }

    /** Build this source's search URL for one mirror base (no I/O). */
    abstract protected function buildSearchUrl(string $base, ReleaseSearchPlan $plan): string;

    /**
     * Map a search-results HTML page (fetched from $base) to candidates. Must
     * return [] — never throw — on a no-results / unexpected / challenge page so
     * the mirror cascade can fail over.
     *
     * @return list<ReleaseCandidate>
     */
    abstract protected function parseToCandidates(string $base, string $html): array;

    /**
     * Resolve $item's download link(s) against a specific mirror (cascade retry).
     * Never throws; [] on failure. (Declared on ReleaseSourceInterface; each
     * concrete source implements its own mirror-specific resolution.)
     *
     * @return list<string>
     */
    abstract public function linksVia(ReleaseCandidate $item, string $mirror, ?DirectDownloadConfig $config = null): array;

    /**
     * Single place the HTTP transport options live. Never throws — failures come
     * back as a structured error so callers can cascade or render them.
     *
     * @return array{html: string, status: int, error: string|null}
     */
    protected function request(string $url): array
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout'       => static::TIMEOUT,
                'max_redirects' => static::MAX_REDIRECTS,
                'headers'       => static::HEADERS,
            ]);

            return ['html' => $response->getContent(false), 'status' => $response->getStatusCode(), 'error' => null];
        } catch (\Throwable $e) {
            return ['html' => '', 'status' => 0, 'error' => $e->getMessage()];
        }
    }
}
