<?php

declare(strict_types=1);

namespace App\Tests\Download;

use App\Download\FilenameTemplate;
use PHPUnit\Framework\TestCase;

final class FilenameTemplateTest extends TestCase
{
    private FilenameTemplate $tpl;

    protected function setUp(): void
    {
        $this->tpl = new FilenameTemplate();
    }

    public function testRendersAllTokensWithExtension(): void
    {
        $name = $this->tpl->render(
            '{Author} - {Title} ({Year})',
            ['author' => 'Pierce Brown', 'title' => 'Red Rising', 'year' => '2014'],
            'epub',
        );
        self::assertSame('Pierce Brown - Red Rising (2014).epub', $name);
    }

    public function testMissingYearDropsEmptyBrackets(): void
    {
        $name = $this->tpl->render(
            '{Author} - {Title} ({Year})',
            ['author' => 'Pierce Brown', 'title' => 'Red Rising', 'year' => ''],
            'epub',
        );
        self::assertSame('Pierce Brown - Red Rising.epub', $name);
    }

    public function testMissingAuthorTrimsLeadingSeparator(): void
    {
        $name = $this->tpl->render(
            '{Author} - {Title} ({Year})',
            ['title' => 'Red Rising', 'year' => '2014'],
            'epub',
        );
        self::assertSame('Red Rising (2014).epub', $name);
    }

    public function testStripsFilesystemUnsafeCharacters(): void
    {
        $name = $this->tpl->render(
            '{Title}',
            ['title' => 'A/B: C? "D" <E>|F'],
            'pdf',
        );
        self::assertSame('AB C D EF.pdf', $name);
    }

    public function testExtensionIsNormalizedAndOptional(): void
    {
        self::assertSame('Red Rising.epub', $this->tpl->render('{Title}', ['title' => 'Red Rising'], '.EPUB'));
        self::assertSame('Red Rising', $this->tpl->render('{Title}', ['title' => 'Red Rising'], null));
        self::assertSame('Red Rising', $this->tpl->render('{Title}', ['title' => 'Red Rising'], ''));
    }

    public function testEmptyTemplateFallsBackToDefault(): void
    {
        $name = $this->tpl->render('', ['author' => 'A', 'title' => 'B', 'year' => '1999'], 'mobi');
        self::assertSame('A - B (1999).mobi', $name);
    }

    public function testAllEmptyTokensFallBackToDownload(): void
    {
        self::assertSame('download.epub', $this->tpl->render('{Author} - {Title}', [], 'epub'));
    }
}
