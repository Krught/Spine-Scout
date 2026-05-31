<?php

declare(strict_types=1);

namespace App\Mirror;

/**
 * Operator-supplied list of mirror base URLs for one indexer kind.
 * Immutable; the order matters (first mirror is tried first, then fall through).
 */
final class MirrorList
{
    /** @param list<string> $urls Already-normalized URLs (use ::fromRaw to normalize) */
    private function __construct(public readonly array $urls)
    {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * Construct from a raw operator input (array of strings, comma-separated string,
     * or anything else MirrorListNormalizer accepts).
     *
     * @param iterable<mixed> $raw
     */
    public static function fromRaw(iterable $raw, MirrorListNormalizer $normalizer): self
    {
        return new self($normalizer->normalize($raw));
    }

    /** @param mixed $value JSON-decoded value out of Integration.options */
    public static function fromJsonValue(mixed $value, MirrorListNormalizer $normalizer): self
    {
        if (!is_array($value)) {
            return self::empty();
        }
        return self::fromRaw($value, $normalizer);
    }

    public function isEmpty(): bool
    {
        return $this->urls === [];
    }

    public function count(): int
    {
        return count($this->urls);
    }

    /** @return list<string> */
    public function toArray(): array
    {
        return $this->urls;
    }
}
