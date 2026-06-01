<?php

declare(strict_types=1);

namespace App\Tests\Search\DirectDownload;

use App\Mirror\MirrorListNormalizer;
use App\Search\DirectDownload\DirectDownloadConfig;
use PHPUnit\Framework\TestCase;

final class DirectDownloadConfigTest extends TestCase
{
    public function testRoundTripsOutputAndFilenameAndFastFlag(): void
    {
        $config = DirectDownloadConfig::fromArray([
            'indexerPriority'     => [['id' => 'annas_archive', 'enabled' => true]],
            'mirrors'             => ['annas_archive' => ['https://m.test']],
            'fastDownloadEnabled' => true,
            'outputDirectory'     => '/var/www/html/library',
            'filenameTemplate'    => '{Title} - {Author}',
        ], new MirrorListNormalizer());

        self::assertTrue($config->fastDownloadEnabled);
        self::assertSame('/var/www/html/library', $config->outputDirectory);
        self::assertSame('{Title} - {Author}', $config->filenameTemplate);

        $reloaded = DirectDownloadConfig::fromArray($config->toArray(), new MirrorListNormalizer());
        self::assertSame($config->outputDirectory, $reloaded->outputDirectory);
        self::assertSame($config->filenameTemplate, $reloaded->filenameTemplate);
        self::assertSame($config->fastDownloadEnabled, $reloaded->fastDownloadEnabled);
    }

    public function testDefaultsWhenAbsent(): void
    {
        $config = DirectDownloadConfig::fromArray([], new MirrorListNormalizer());
        self::assertSame(DirectDownloadConfig::DEFAULT_OUTPUT_DIRECTORY, $config->outputDirectory);
        self::assertSame('/var/www/html/library', $config->outputDirectory);
        self::assertSame(DirectDownloadConfig::DEFAULT_FILENAME_TEMPLATE, $config->filenameTemplate);
        self::assertFalse($config->fastDownloadEnabled);
        // Bypass defaults to external (the bundled FlareSolverr) at its address.
        self::assertSame(DirectDownloadConfig::BYPASS_EXTERNAL, $config->bypassMode);
        self::assertSame(DirectDownloadConfig::DEFAULT_FLARESOLVERR_URL, $config->bypassFlaresolverrUrl);
    }

    public function testRoundTripsBypassSettings(): void
    {
        $config = DirectDownloadConfig::fromArray([
            'bypassMode'            => 'external',
            'bypassFlaresolverrUrl' => 'http://10.0.0.5:8191',
        ], new MirrorListNormalizer());

        self::assertSame(DirectDownloadConfig::BYPASS_EXTERNAL, $config->bypassMode);
        self::assertSame('http://10.0.0.5:8191', $config->bypassFlaresolverrUrl);

        $reloaded = DirectDownloadConfig::fromArray($config->toArray(), new MirrorListNormalizer());
        self::assertSame($config->bypassMode, $reloaded->bypassMode);
        self::assertSame($config->bypassFlaresolverrUrl, $reloaded->bypassFlaresolverrUrl);
    }

    public function testUnknownBypassModeFallsBackToExternal(): void
    {
        // Includes the retired 'internal' mode, now an unknown value.
        $config = DirectDownloadConfig::fromArray(['bypassMode' => 'banana'], new MirrorListNormalizer());
        self::assertSame(DirectDownloadConfig::BYPASS_EXTERNAL, $config->bypassMode);

        $legacy = DirectDownloadConfig::fromArray(['bypassMode' => 'internal'], new MirrorListNormalizer());
        self::assertSame(DirectDownloadConfig::BYPASS_EXTERNAL, $legacy->bypassMode);
    }

    public function testBlankFilenameTemplateFallsBackToDefault(): void
    {
        $config = DirectDownloadConfig::fromArray(['filenameTemplate' => '   '], new MirrorListNormalizer());
        self::assertSame(DirectDownloadConfig::DEFAULT_FILENAME_TEMPLATE, $config->filenameTemplate);
    }

    public function testWithIndexerEnabledFlipsOnlyTheTargetRowAndPreservesOrder(): void
    {
        $config = DirectDownloadConfig::fromArray([
            'indexerPriority' => [
                ['id' => 'annas_archive', 'enabled' => true],
                ['id' => 'libgen', 'enabled' => true],
            ],
            'mirrors' => [],
        ], new MirrorListNormalizer());

        $updated = $config->withIndexerEnabled('libgen', false);

        // Original untouched (immutability).
        self::assertTrue($config->isIndexerEnabled('libgen'));
        // Copy has the flag flipped, others unchanged, order preserved.
        self::assertFalse($updated->isIndexerEnabled('libgen'));
        self::assertTrue($updated->isIndexerEnabled('annas_archive'));
        self::assertSame(['annas_archive', 'libgen'], array_map(static fn ($r) => $r['id'], $updated->indexerPriority));
    }

    public function testWithIndexerEnabledAppendsAbsentId(): void
    {
        $config = DirectDownloadConfig::fromArray([
            'indexerPriority' => [['id' => 'annas_archive', 'enabled' => true]],
            'mirrors' => [],
        ], new MirrorListNormalizer());

        $updated = $config->withIndexerEnabled('zlibrary', true);

        self::assertTrue($updated->isIndexerEnabled('zlibrary'));
        self::assertSame(['annas_archive', 'zlibrary'], array_map(static fn ($r) => $r['id'], $updated->indexerPriority));
    }
}
