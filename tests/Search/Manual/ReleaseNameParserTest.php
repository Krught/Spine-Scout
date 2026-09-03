<?php

declare(strict_types=1);

namespace App\Tests\Search\Manual;

use App\Download\FilenameTemplate;
use App\Search\Manual\ReleaseNameParser;
use PHPUnit\Framework\TestCase;

final class ReleaseNameParserTest extends TestCase
{
    private const EBOOK_TEMPLATE = '{Author} - {Title} ({Year})';
    private const AUDIO_TEMPLATE = '{Author} - {Title}';

    private ReleaseNameParser $parser;

    protected function setUp(): void
    {
        $this->parser = new ReleaseNameParser(new FilenameTemplate());
    }

    /** The canonical dot-glued scene shape: author, dash, title, year, tags, extension. */
    public function testParsesDotGluedSceneName(): void
    {
        $parsed = ReleaseNameParser::parse('Pierce.Brown-Red.Rising.2014.Retail.EPUB');

        self::assertSame('Pierce Brown', $parsed['author']);
        self::assertSame('Red Rising', $parsed['title']);
        self::assertSame('2014', $parsed['year']);
        self::assertSame('epub', $parsed['format']);
        self::assertTrue($parsed['confident']);
    }

    /** Bracketed/parenthesised/braced tag groups are mined for format + year, then dropped. */
    public function testStripsTagGroupsAndKeepsTheirFormatAndYear(): void
    {
        $parsed = ReleaseNameParser::parse('Pierce Brown - Red Rising [EPUB] (retail) {2014}');

        self::assertSame('Pierce Brown', $parsed['author']);
        self::assertSame('Red Rising', $parsed['title']);
        self::assertSame('2014', $parsed['year']);
        self::assertSame('epub', $parsed['format']);
    }

    public function testRecognizesBracedAudiobookFormatTag(): void
    {
        $parsed = ReleaseNameParser::parse('Andy Weir - Project Hail Mary {M4B} 128kbps');

        self::assertSame('Andy Weir', $parsed['author']);
        self::assertSame('Project Hail Mary', $parsed['title']);
        self::assertSame('m4b', $parsed['format']);
    }

    /** A trailing scene group tag is not part of the title. */
    public function testStripsTrailingGroupTag(): void
    {
        $parsed = ReleaseNameParser::parse('Pierce Brown - Red Rising-KTR');

        self::assertSame('Pierce Brown', $parsed['author']);
        self::assertSame('Red Rising', $parsed['title']);
    }

    /** "Title - Author" is recognized when the left half plainly reads as a title. */
    public function testSplitsTitleAuthorShape(): void
    {
        $parsed = ReleaseNameParser::parse('The Long Way to a Small Angry Planet - Becky Chambers');

        self::assertSame('Becky Chambers', $parsed['author']);
        self::assertSame('The Long Way to a Small Angry Planet', $parsed['title']);
    }

    /** "Author - Series NN - Title": the ends are what matter. */
    public function testThreePartNameTakesOuterEnds(): void
    {
        $parsed = ReleaseNameParser::parse('Brandon Sanderson - Mistborn 01 - The Final Empire.epub');

        self::assertSame('Brandon Sanderson', $parsed['author']);
        self::assertSame('The Final Empire', $parsed['title']);
        self::assertSame('epub', $parsed['format']);
    }

    /**
     * No dash means no author — and an author is never invented, so the parse is
     * not confident and the caller falls back to the raw name.
     */
    public function testNeverInventsAnAuthorWithoutASeparator(): void
    {
        $parsed = ReleaseNameParser::parse('Dungeon Crawler Carl 2020 M4B 64kbps');

        self::assertNull($parsed['author']);
        self::assertSame('Dungeon Crawler Carl', $parsed['title']);
        self::assertSame('2020', $parsed['year']);
        self::assertFalse($parsed['confident']);
    }

    /** A hyphenated first name is not an author/title separator. */
    public function testDoesNotSplitAHyphenatedWord(): void
    {
        $parsed = ReleaseNameParser::parse('Jean-Luc.Picard.Memoirs.epub');

        self::assertNull($parsed['author']);
        self::assertFalse($parsed['confident']);
    }

    /** A year that IS the whole title survives — removing it would leave nothing. */
    public function testKeepsAYearThatIsTheEntireTitle(): void
    {
        $parsed = ReleaseNameParser::parse('1984.epub');

        self::assertSame('1984', $parsed['title']);
        self::assertNull($parsed['year']);
        self::assertSame('epub', $parsed['format']);
    }

    public function testEmptyNameParsesToNothing(): void
    {
        $parsed = ReleaseNameParser::parse('   ');

        self::assertSame('', $parsed['title']);
        self::assertFalse($parsed['confident']);
    }

    // --- rendering --------------------------------------------------------

    /** A confident parse renders through the operator's ebook template + extension. */
    public function testSuggestRendersEbookTemplate(): void
    {
        self::assertSame(
            'Pierce Brown - Red Rising (2014).epub',
            $this->parser->suggest('Pierce.Brown-Red.Rising.2014.Retail.EPUB', self::EBOOK_TEMPLATE, 'epub'),
        );
    }

    /** An audiobook renders the album FOLDER name — no extension appended. */
    public function testSuggestRendersAudiobookFolderWithoutExtension(): void
    {
        self::assertSame(
            'Andy Weir - Project Hail Mary',
            $this->parser->suggest('Andy Weir - Project Hail Mary {M4B}', self::AUDIO_TEMPLATE),
        );
    }

    /** A missing year leaves no dangling "()" — FilenameTemplate's own rule. */
    public function testSuggestDropsTheYearBracketsWhenThereIsNoYear(): void
    {
        self::assertSame(
            'Becky Chambers - A Closed and Common Orbit.epub',
            $this->parser->suggest('A Closed and Common Orbit - Becky Chambers', self::EBOOK_TEMPLATE, 'epub'),
        );
    }

    /** Not confident → the raw release name, verbatim (never re-shaped). */
    public function testSuggestFallsBackToTheRawReleaseName(): void
    {
        self::assertSame(
            'Dungeon Crawler Carl 2020 M4B 64kbps.m4b',
            $this->parser->suggest('Dungeon Crawler Carl 2020 M4B 64kbps', self::EBOOK_TEMPLATE, 'm4b'),
        );
    }

    /** The fallback is still sanitized — a raw name may contain anything. */
    public function testSuggestSanitizesTheRawFallback(): void
    {
        self::assertSame(
            'evil.epub',
            $this->parser->suggest('../../etc/evil', self::EBOOK_TEMPLATE, 'epub'),
        );
    }

    // --- sanitisation -----------------------------------------------------

    public function testSanitizeStripsPathTraversal(): void
    {
        self::assertSame('passwd.epub', ReleaseNameParser::sanitize('../../etc/passwd', 'epub'));
        self::assertSame('passwd', ReleaseNameParser::sanitize('..\\..\\windows\\passwd'));
        self::assertSame('download', ReleaseNameParser::sanitize('..'));
        self::assertSame('download', ReleaseNameParser::sanitize('   '));
    }

    public function testSanitizeStripsIllegalCharacters(): void
    {
        self::assertSame('Red Rising.epub', ReleaseNameParser::sanitize('Red: Rising?*.epub', 'epub'));
    }

    /** The extension is ensured exactly once, whatever case the name carried. */
    public function testSanitizeDoesNotDoubleTheExtension(): void
    {
        self::assertSame('Red Rising.EPUB', ReleaseNameParser::sanitize('Red Rising.EPUB', 'epub'));
        self::assertSame('Red Rising.epub', ReleaseNameParser::sanitize('Red Rising', 'epub'));
    }

    public function testSanitizeCapsTheLength(): void
    {
        // 180 characters of name plus ".epub" — a single path component must stay
        // under the filesystem's 255-byte cap even before FileMover's " (1)" suffix.
        self::assertSame(185, mb_strlen(ReleaseNameParser::sanitize(str_repeat('a', 400), 'epub')));
    }
}
