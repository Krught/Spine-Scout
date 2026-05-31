<?php

declare(strict_types=1);

namespace App\Search\BestMatch;

/**
 * Operator-tunable policy for auto-picking a release candidate out of a search result set.
 *
 * Stored as JSON inside Integration.options for the Integration row of kind
 * `best_match`. Hydrated via ::fromArray() / persisted via ::toArray().
 *
 * Immutable: setters return a clone. This makes "build a policy from a form,
 * compare against the stored one, decide whether to flush" trivial.
 */
final class BestMatchPolicy
{
    public const TIE_MOST_DOWNLOADED = 'most_downloaded';
    public const TIE_MOST_SEEDERS    = 'most_seeders';
    public const TIE_LARGEST_SIZE    = 'largest_size';
    public const TIE_SMALLEST_SIZE   = 'smallest_size';

    public const TIE_BREAKERS = [
        self::TIE_MOST_DOWNLOADED,
        self::TIE_MOST_SEEDERS,
        self::TIE_LARGEST_SIZE,
        self::TIE_SMALLEST_SIZE,
    ];

    /**
     * @param list<string> $allowedFormats     Empty = allow all
     * @param list<string> $formatPriority     Empty = no format preference
     * @param list<string> $sourcePriority     Empty = treat all sources equally
     * @param list<string> $tieBreakers        Empty = stable (first-found) ordering
     * @param list<string> $languagePriority   Empty = no language preference
     */
    public function __construct(
        public readonly array $allowedFormats = [],
        public readonly array $formatPriority = ['epub', 'mobi', 'azw3', 'pdf', 'cbz', 'cbr'],
        public readonly array $sourcePriority = [],
        public readonly array $tieBreakers = [
            self::TIE_MOST_DOWNLOADED,
            self::TIE_MOST_SEEDERS,
            self::TIE_LARGEST_SIZE,
        ],
        public readonly ?int $minSizeBytes = 50_000,
        public readonly ?int $maxSizeBytes = null,
        public readonly ?int $minSeeders = 1,
        public readonly bool $requireIsbnMatch = false,
        public readonly array $languagePriority = ['en'],
        public readonly int $minMatchScore = 50,
    ) {
    }

    public static function default(): self
    {
        return new self();
    }

    /**
     * @param array<string, mixed>|null $raw JSON-decoded options blob (or null when missing)
     */
    public static function fromArray(?array $raw): self
    {
        if ($raw === null) {
            return self::default();
        }
        $def = self::default();
        return new self(
            allowedFormats:   self::coerceStringList($raw['allowedFormats']   ?? null),
            formatPriority:   self::coerceStringList($raw['formatPriority']   ?? null) ?: $def->formatPriority,
            sourcePriority:   self::coerceStringList($raw['sourcePriority']   ?? null),
            tieBreakers:      self::filterValues(
                self::coerceStringList($raw['tieBreakers'] ?? null) ?: $def->tieBreakers,
                self::TIE_BREAKERS,
            ),
            minSizeBytes:     self::coerceNullableInt($raw['minSizeBytes'] ?? null, $def->minSizeBytes),
            maxSizeBytes:     self::coerceNullableInt($raw['maxSizeBytes'] ?? null, null),
            minSeeders:       self::coerceNullableInt($raw['minSeeders']   ?? null, $def->minSeeders),
            requireIsbnMatch: (bool) ($raw['requireIsbnMatch'] ?? false),
            languagePriority: self::coerceStringList($raw['languagePriority'] ?? null) ?: $def->languagePriority,
            minMatchScore: self::coerceNullableInt($raw['minMatchScore'] ?? null, $def->minMatchScore) ?? $def->minMatchScore,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'allowedFormats'   => $this->allowedFormats,
            'formatPriority'   => $this->formatPriority,
            'sourcePriority'   => $this->sourcePriority,
            'tieBreakers'      => $this->tieBreakers,
            'minSizeBytes'     => $this->minSizeBytes,
            'maxSizeBytes'     => $this->maxSizeBytes,
            'minSeeders'       => $this->minSeeders,
            'requireIsbnMatch' => $this->requireIsbnMatch,
            'languagePriority' => $this->languagePriority,
            'minMatchScore'    => $this->minMatchScore,
        ];
    }

    /** @return list<string> */
    private static function coerceStringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $v) {
            if (is_string($v) && $v !== '') {
                $out[] = $v;
            }
        }
        return $out;
    }

    /**
     * @param list<string> $items
     * @param list<string> $allowed
     * @return list<string>
     */
    private static function filterValues(array $items, array $allowed): array
    {
        $out = [];
        $seen = [];
        foreach ($items as $item) {
            if (!in_array($item, $allowed, true) || isset($seen[$item])) {
                continue;
            }
            $seen[$item] = true;
            $out[] = $item;
        }
        return $out;
    }

    private static function coerceNullableInt(mixed $value, ?int $default): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }
        return $default;
    }
}
