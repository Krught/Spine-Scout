<?php

declare(strict_types=1);

namespace App\Tests\Download\Torrent;

use App\Download\Torrent\TorrentClientConfig;
use PHPUnit\Framework\TestCase;

/**
 * Parsing of the torrent-aware request-deletion settings on the shared
 * TorrentClientConfig blob: the ask-what-to-do toggle, the default action
 * applied when it's off, and the tag applied to a torrent kept seeding after
 * its request was deleted. Existing installs carry none of these keys, so
 * absent keys must yield the defaults (no migration needed).
 */
final class TorrentDeletePromptConfigTest extends TestCase
{
    public function testDeletePromptDefaultsWhenKeysAreAbsent(): void
    {
        // Older stored blobs (and a missing row → null) know nothing about these keys.
        $stored = TorrentClientConfig::fromArray(['category' => 'ab']);
        self::assertTrue($stored->deletePromptEnabled);
        self::assertSame('spinescout-unmonitored', $stored->releasedTag);
        self::assertSame('keep', $stored->deleteDefaultAction);

        self::assertTrue(TorrentClientConfig::fromArray(null)->deletePromptEnabled);
        self::assertSame(TorrentClientConfig::DEFAULT_RELEASED_TAG, TorrentClientConfig::fromArray(null)->releasedTag);
        self::assertSame(TorrentClientConfig::DELETE_ACTION_KEEP, TorrentClientConfig::fromArray(null)->deleteDefaultAction);

        self::assertTrue(TorrentClientConfig::default()->deletePromptEnabled);
        self::assertSame(TorrentClientConfig::DEFAULT_RELEASED_TAG, TorrentClientConfig::default()->releasedTag);
        self::assertSame(TorrentClientConfig::DELETE_ACTION_KEEP, TorrentClientConfig::default()->deleteDefaultAction);
    }

    public function testDeletePromptSettingsRoundTrip(): void
    {
        $config = TorrentClientConfig::fromArray([
            'deletePromptEnabled' => false,
            'releasedTag'         => 'my-own-tag',
            'deleteDefaultAction' => 'remove',
        ]);
        self::assertFalse($config->deletePromptEnabled);
        self::assertSame('my-own-tag', $config->releasedTag);
        self::assertSame(TorrentClientConfig::DELETE_ACTION_REMOVE, $config->deleteDefaultAction);

        $reloaded = TorrentClientConfig::fromArray($config->toArray());
        self::assertFalse($reloaded->deletePromptEnabled);
        self::assertSame('my-own-tag', $reloaded->releasedTag);
        self::assertSame(TorrentClientConfig::DELETE_ACTION_REMOVE, $reloaded->deleteDefaultAction);
    }

    public function testDeleteDefaultActionNormalizesToKeepOnAnythingUnknown(): void
    {
        // Unknown or malformed stored/submitted values must never leak through —
        // keep is the safe fallback (never silently deletes someone's data).
        self::assertSame('keep', TorrentClientConfig::fromArray(['deleteDefaultAction' => 'purge'])->deleteDefaultAction);
        self::assertSame('keep', TorrentClientConfig::fromArray(['deleteDefaultAction' => ''])->deleteDefaultAction);
        self::assertSame('keep', TorrentClientConfig::fromArray(['deleteDefaultAction' => 'REMOVE'])->deleteDefaultAction);
        self::assertSame('keep', TorrentClientConfig::fromArray(['deleteDefaultAction' => 42])->deleteDefaultAction);
        // Exact known values pass, with surrounding whitespace tolerated.
        self::assertSame('remove', TorrentClientConfig::fromArray(['deleteDefaultAction' => ' remove '])->deleteDefaultAction);
        self::assertSame('keep', TorrentClientConfig::fromArray(['deleteDefaultAction' => 'keep'])->deleteDefaultAction);
    }

    public function testStoredEmptyReleasedTagMeansDontTagAndSurvivesARoundTrip(): void
    {
        // Unlike absent (→ default), a stored empty string is the operator's explicit
        // "keep seeding without tagging" and must not snap back to the default.
        $config = TorrentClientConfig::fromArray(['releasedTag' => '']);
        self::assertSame('', $config->releasedTag);
        self::assertSame('', TorrentClientConfig::fromArray($config->toArray())->releasedTag);
    }

    public function testReleasedTagIsTrimmed(): void
    {
        self::assertSame('kept', TorrentClientConfig::fromArray(['releasedTag' => '  kept  '])->releasedTag);
        // Whitespace-only trims to empty — treated as "don't tag", not the default.
        self::assertSame('', TorrentClientConfig::fromArray(['releasedTag' => '   '])->releasedTag);
    }
}
