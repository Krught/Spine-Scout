<?php

declare(strict_types=1);

namespace App\Search\Torrent;

/**
 * Operator tuning for Prowlarr audiobook search + torrent matching. The connection
 * itself (base URL + API key) lives on the Integration row of kind `prowlarr`
 * (baseUrl column + credentials['token']); this value object holds only the
 * search/scoring knobs stored in that row's options['config'] blob.
 *
 * Immutable.
 */
final class ProwlarrConfig
{
    /** Newznab/Torznab "Audio/Audiobook" category. The default audiobook search scope. */
    public const DEFAULT_CATEGORIES = [3030];

    /** Pass the Torznab category ids with the query (the classic scope). */
    public const METHOD_CATEGORIES = 'categories';
    /** No category filter at all — every indexer result comes back as-is. */
    public const METHOD_RAW = 'raw';
    /** No category filter on the query; results the app can positively classify as the wrong content type (ebook vs audiobook) are dropped afterwards. */
    public const METHOD_FILTERED = 'filtered';

    public const METHODS = [self::METHOD_CATEGORIES, self::METHOD_RAW, self::METHOD_FILTERED];

    public const DEFAULT_MIN_SEEDERS = 1;

    /**
     * Weights for the four scoring axes. They need not sum to 1 — the scorer
     * normalizes each axis to 0..1 and multiplies by its weight, so these are
     * relative importances. Title/author match dominates by default so a healthy
     * but wrong torrent never beats the right book.
     *
     * @var array{match: float, seeders: float, size: float, format: float}
     */
    public const DEFAULT_WEIGHTS = [
        'match'   => 0.5,
        'seeders' => 0.25,
        'size'    => 0.15,
        'format'  => 0.10,
    ];

    /**
     * @param list<int>                                                    $categories  Torznab category ids to search
     * @param int                                                          $minSeeders  Drop releases below this seed count
     * @param int|null                                                     $maxSizeBytes Drop releases larger than this (guards against pathological packs); null = no cap
     * @param array{match: float, seeders: float, size: float, format: float} $weights  Relative scoring weights
     * @param string                                                       $searchMethod One of METHODS — how the category scope is applied (see the METHOD_* docs)
     */
    public function __construct(
        public readonly array $categories = self::DEFAULT_CATEGORIES,
        public readonly int $minSeeders = self::DEFAULT_MIN_SEEDERS,
        public readonly ?int $maxSizeBytes = null,
        public readonly array $weights = self::DEFAULT_WEIGHTS,
        public readonly string $searchMethod = self::METHOD_CATEGORIES,
    ) {
    }

    public static function default(): self
    {
        return new self();
    }

    /**
     * @param array<string, mixed>|null $raw JSON-decoded options['config'] blob
     */
    public static function fromArray(?array $raw): self
    {
        if ($raw === null) {
            return self::default();
        }

        $categories = [];
        foreach ((array) ($raw['categories'] ?? []) as $c) {
            if (is_int($c) || (is_string($c) && ctype_digit($c))) {
                $categories[] = (int) $c;
            }
        }
        $categories = array_values(array_unique($categories));
        if ($categories === []) {
            $categories = self::DEFAULT_CATEGORIES;
        }

        $minSeeders = isset($raw['minSeeders']) && is_numeric($raw['minSeeders'])
            ? max(0, (int) $raw['minSeeders'])
            : self::DEFAULT_MIN_SEEDERS;

        $maxSizeBytes = isset($raw['maxSizeBytes']) && is_numeric($raw['maxSizeBytes']) && (int) $raw['maxSizeBytes'] > 0
            ? (int) $raw['maxSizeBytes']
            : null;

        $weights = self::DEFAULT_WEIGHTS;
        if (is_array($raw['weights'] ?? null)) {
            foreach (self::DEFAULT_WEIGHTS as $axis => $default) {
                $v = $raw['weights'][$axis] ?? null;
                if (is_numeric($v)) {
                    $weights[$axis] = max(0.0, (float) $v);
                }
            }
        }

        $searchMethod = $raw['searchMethod'] ?? null;
        if (!is_string($searchMethod) || !in_array($searchMethod, self::METHODS, true)) {
            $searchMethod = self::METHOD_CATEGORIES;
        }

        return new self($categories, $minSeeders, $maxSizeBytes, $weights, $searchMethod);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'categories'   => $this->categories,
            'minSeeders'   => $this->minSeeders,
            'maxSizeBytes' => $this->maxSizeBytes,
            'weights'      => $this->weights,
            'searchMethod' => $this->searchMethod,
        ];
    }

    public function matchPolicy(): TorrentMatchPolicy
    {
        return TorrentMatchPolicy::fromProwlarrConfig($this);
    }
}
