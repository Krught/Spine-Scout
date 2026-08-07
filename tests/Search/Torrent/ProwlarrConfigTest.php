<?php

declare(strict_types=1);

namespace App\Tests\Search\Torrent;

use App\Search\Torrent\ProwlarrConfig;
use PHPUnit\Framework\TestCase;

final class ProwlarrConfigTest extends TestCase
{
    public function testDefaultsCarryBothCategoryLists(): void
    {
        $config = ProwlarrConfig::default();

        self::assertSame(ProwlarrConfig::DEFAULT_CATEGORIES, $config->categories);
        self::assertSame(ProwlarrConfig::DEFAULT_BOOK_CATEGORIES, $config->bookCategories);
    }

    public function testCategoryListsRoundTripThroughToArrayAndFromArray(): void
    {
        $config = new ProwlarrConfig(categories: [3030, 3040], bookCategories: [7020, 7060]);

        $rehydrated = ProwlarrConfig::fromArray($config->toArray());

        self::assertSame([3030, 3040], $rehydrated->categories);
        self::assertSame([7020, 7060], $rehydrated->bookCategories);
    }

    public function testFromArrayNormalizesBookCategoriesLikeTheAudiobookList(): void
    {
        $config = ProwlarrConfig::fromArray([
            'categories'     => ['3030', 3030, 'junk', null],
            'bookCategories' => ['7020', 7020, 'junk', null, 7000],
        ]);

        self::assertSame([3030], $config->categories);
        self::assertSame([7020, 7000], $config->bookCategories);
    }

    public function testFromArrayFallsBackToDefaultsForEmptyOrMissingLists(): void
    {
        self::assertSame(
            ProwlarrConfig::DEFAULT_BOOK_CATEGORIES,
            ProwlarrConfig::fromArray(['bookCategories' => []])->bookCategories,
        );
        // Older stored blobs predate the key entirely.
        self::assertSame(
            ProwlarrConfig::DEFAULT_BOOK_CATEGORIES,
            ProwlarrConfig::fromArray(['categories' => [3030]])->bookCategories,
        );
        self::assertSame(
            ProwlarrConfig::DEFAULT_CATEGORIES,
            ProwlarrConfig::fromArray(['bookCategories' => [7020]])->categories,
        );
        self::assertSame(ProwlarrConfig::DEFAULT_BOOK_CATEGORIES, ProwlarrConfig::fromArray(null)->bookCategories);
    }
}
