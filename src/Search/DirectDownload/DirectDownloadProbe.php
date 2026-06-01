<?php

declare(strict_types=1);

namespace App\Search\DirectDownload;

use App\Entity\Book;
use App\Repository\IntegrationRepository;
use App\Search\Source\ReleaseCandidate;
use App\Search\Source\ReleaseSearchPlan;
use App\Search\Source\ReleaseSourceInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Manual-probe helper for the direct-download search path, shared by the
 * `spinescout:dd:probe` console command and the Settings → Development page.
 *
 * Multi-source: builds each enabled source's search URL from the operator's saved
 * settings — optionally with EPHEMERAL enable/disable overrides applied for one
 * run only (never persisted) — and runs the search so the operator can compare
 * what every source returns side by side. Unlike the automatic workflow (a
 * failover cascade that stops at the first qualifying source), the probe runs
 * every enabled source.
 */
final class DirectDownloadProbe
{
    /**
     * @param iterable<ReleaseSourceInterface> $sources
     */
    public function __construct(
        private readonly IntegrationRepository $integrations,
        #[AutowireIterator('app.release_source')]
        private readonly iterable $sources,
    ) {
    }

    public function config(): DirectDownloadConfig
    {
        return $this->integrations->getDirectDownloadConfig();
    }

    public function buildPlan(
        ?string $isbn,
        ?string $author,
        ?string $title,
        ?string $publisher = null,
        ?string $publishedDate = null,
        ?string $language = null,
    ): ReleaseSearchPlan {
        $isbn = trim((string) $isbn);
        $author = trim((string) $author);
        $title = trim((string) $title);

        $book = new Book('probe', $isbn !== '' ? $isbn : 'manual', $title);
        if ($isbn !== '') {
            $book->setIsbns([$isbn]);
        }
        if ($author !== '') {
            $book->setAuthor($author);
        }
        if (trim((string) $publisher) !== '') {
            $book->setPublisher(trim((string) $publisher));
        }
        if (trim((string) $publishedDate) !== '') {
            $book->setPublishedDate(trim((string) $publishedDate));
        }
        if (trim((string) $language) !== '') {
            $book->setLanguage(trim((string) $language));
        }

        return new ReleaseSearchPlan(
            book: $book,
            isbnCandidates: $isbn !== '' ? [$isbn] : [],
            author: $author,
            titleVariants: $title !== '' ? [$title] : [],
        );
    }

    /**
     * The saved config with ephemeral enable/disable overrides applied. $enabledIds
     * is the set of source ids enabled for this run; any known source absent from
     * it is disabled. Null returns the saved config unchanged. Never persisted.
     *
     * @param list<string>|null $enabledIds
     */
    public function effectiveConfig(?array $enabledIds): DirectDownloadConfig
    {
        $config = $this->config();
        if ($enabledIds === null) {
            return $config;
        }

        $set = array_fill_keys($enabledIds, true);
        foreach (DirectDownloadSource::ids() as $id) {
            $config = $config->withIndexerEnabled($id, isset($set[$id]));
        }

        return $config;
    }

    /**
     * Run every enabled source for $plan under $config, returning one report per
     * source in priority order: the search descriptor (mirror + URL, or the reason
     * it was skipped) and the normalized records it found. Disabled sources are
     * omitted; enabled-but-unconfigured ones are reported with a reason.
     *
     * @return list<array{search: SourceSearch, records: list<array<string, string|null>>}>
     */
    public function run(ReleaseSearchPlan $plan, DirectDownloadConfig $config): array
    {
        $byId = $this->sourcesById();

        $out = [];
        foreach ($config->indexerPriority as $row) {
            if (!($row['enabled'] ?? false)) {
                continue;
            }
            $id = $row['id'];
            $label = DirectDownloadSource::tryFromId($id)?->label() ?? $id;
            $source = $byId[$id] ?? null;

            if ($source === null) {
                $out[] = ['search' => new SourceSearch($id, $label, null, null, false, 'No adapter for this source.'), 'records' => []];
                continue;
            }
            if ($config->mirrorsFor($id)->toArray() === []) {
                $out[] = ['search' => new SourceSearch($id, $label, null, null, false, 'No mirrors configured for this source.'), 'records' => []];
                continue;
            }

            ['mirror' => $mirror, 'url' => $url] = $source->searchPlanUrl($plan, $config);
            $records = array_map(
                static fn (ReleaseCandidate $c): array => self::normalize($c),
                $source->search($plan, $config),
            );

            $out[] = ['search' => new SourceSearch($id, $label, $mirror, $url, true), 'records' => $records];
        }

        return $out;
    }

    /**
     * The search descriptor for every enabled source under $config, WITHOUT
     * performing any request (the "Generate URLs" path).
     *
     * @return list<SourceSearch>
     */
    public function plannedSearches(ReleaseSearchPlan $plan, DirectDownloadConfig $config): array
    {
        $byId = $this->sourcesById();

        $out = [];
        foreach ($config->indexerPriority as $row) {
            if (!($row['enabled'] ?? false)) {
                continue;
            }
            $id = $row['id'];
            $label = DirectDownloadSource::tryFromId($id)?->label() ?? $id;
            $source = $byId[$id] ?? null;

            if ($source === null) {
                $out[] = new SourceSearch($id, $label, null, null, false, 'No adapter for this source.');
                continue;
            }
            if ($config->mirrorsFor($id)->toArray() === []) {
                $out[] = new SourceSearch($id, $label, null, null, false, 'No mirrors configured for this source.');
                continue;
            }

            ['mirror' => $mirror, 'url' => $url] = $source->searchPlanUrl($plan, $config);
            $out[] = new SourceSearch($id, $label, $mirror, $url, true);
        }

        return $out;
    }

    /** Resolve one record's detail (ISBNs + download links) for the named source. */
    public function resolveDetail(string $sourceId, ReleaseCandidate $candidate, DirectDownloadConfig $config): array
    {
        $source = $this->sourcesById()[$sourceId] ?? null;
        if ($source === null) {
            return ['isbns' => [], 'raw' => [], 'links' => [], 'error' => 'No adapter for this source.'];
        }

        return $source->resolveDetail($candidate, $config);
    }

    /** @return array<string, ReleaseSourceInterface> */
    private function sourcesById(): array
    {
        $byId = [];
        foreach ($this->sources as $source) {
            $byId[$source->sourceId()] = $source;
        }

        return $byId;
    }

    /**
     * Flatten a candidate to the source-agnostic shape the probe table renders.
     *
     * @return array<string, string|null>
     */
    private static function normalize(ReleaseCandidate $candidate): array
    {
        $size = $candidate->extra['size'] ?? null;

        return [
            'id'        => $candidate->sourceId,
            'title'     => $candidate->title,
            'author'    => $candidate->author,
            'format'    => $candidate->format,
            'language'  => $candidate->language,
            'publisher' => $candidate->publisher,
            'year'      => $candidate->year,
            'size'      => is_string($size) ? $size : null,
            'mirror'    => is_string($candidate->extra['mirror'] ?? null) ? $candidate->extra['mirror'] : null,
            'infoUrl'   => $candidate->infoUrl,
        ];
    }
}
