<?php

declare(strict_types=1);

namespace App\Tests\Download\Metadata;

use App\Download\Metadata\EpubMetadataWriter;
use App\Entity\Book;
use PHPUnit\Framework\TestCase;

final class EpubMetadataWriterTest extends TestCase
{
    private const OPF_NS = 'http://www.idpf.org/2007/opf';
    private const DC_NS  = 'http://purl.org/dc/elements/1.1/';

    private string $epub;

    protected function setUp(): void
    {
        $this->epub = sys_get_temp_dir() . '/spinescout_epub_' . bin2hex(random_bytes(6)) . '.epub';
        $this->buildFixture($this->epub);
    }

    protected function tearDown(): void
    {
        @unlink($this->epub);
    }

    public function testRewritesDublinCoreAndInjectsCover(): void
    {
        $book = (new Book('hardcover', 'ext-1', 'New Title'))
            ->setAuthor('New Author')
            ->setPublisher('New Publisher')
            ->setLanguage('en')
            ->setDescription('A fresh description.')
            ->setPublishedDate('2024')
            ->setIsbn('9781234567897');

        (new EpubMetadataWriter())->write($this->epub, $book, ['COVERBYTES', 'image/jpeg']);

        [$dom, $xpath] = $this->openOpf($this->epub);

        self::assertSame('New Title', $this->text($xpath, '//dc:title'));
        self::assertSame('New Author', $this->text($xpath, '//dc:creator'));
        self::assertSame('New Publisher', $this->text($xpath, '//dc:publisher'));
        self::assertSame('en', $this->text($xpath, '//dc:language'));
        self::assertSame('A fresh description.', $this->text($xpath, '//dc:description'));
        self::assertSame('2024', $this->text($xpath, '//dc:date'));

        // Old values are gone.
        self::assertSame(1, $xpath->query('//dc:title')->length);
        self::assertNull($this->firstText($xpath, '//dc:title[text()="Old Title"]'));

        // ISBN: the unique-identifier is preserved; the old ISBN is replaced by ours.
        self::assertSame('urn:uuid:0000', $this->text($xpath, '//dc:identifier[@id="BookId"]'));
        $isbn = $xpath->query('//dc:identifier[@opf:scheme="ISBN"]');
        self::assertSame(1, $isbn->length);
        self::assertSame('9781234567897', trim($isbn->item(0)->textContent));

        // Cover: our manifest item is the sole cover-image and the EPUB2 pointer matches.
        $coverItems = $xpath->query('//opf:manifest/opf:item[contains(@properties, "cover-image")]');
        self::assertSame(1, $coverItems->length);
        $coverItem = $coverItems->item(0);
        self::assertInstanceOf(\DOMElement::class, $coverItem);
        self::assertSame('spinescout-cover-image', $coverItem->getAttribute('id'));
        self::assertSame('spinescout-cover.jpg', $coverItem->getAttribute('href'));
        self::assertSame('image/jpeg', $coverItem->getAttribute('media-type'));

        $metaCover = $xpath->query('//opf:metadata/opf:meta[@name="cover"]');
        self::assertSame(1, $metaCover->length);
        self::assertSame('spinescout-cover-image', $metaCover->item(0)->getAttribute('content'));

        // The image bytes were written next to the OPF.
        $zip = new \ZipArchive();
        $zip->open($this->epub);
        self::assertSame('COVERBYTES', $zip->getFromName('OEBPS/spinescout-cover.jpg'));
        $zip->close();
    }

    public function testThrowsWhenNotAnEpub(): void
    {
        file_put_contents($this->epub, 'this is not a zip');
        $this->expectException(\RuntimeException::class);
        (new EpubMetadataWriter())->write($this->epub, new Book('hardcover', 'x', 'T'), null);
    }

    /** Build a minimal but valid EPUB OPF (EPUB2 cover convention) into $path. */
    private function buildFixture(string $path): void
    {
        $container = <<<XML
            <?xml version="1.0"?>
            <container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container">
              <rootfiles>
                <rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/>
              </rootfiles>
            </container>
            XML;

        $opf = <<<XML
            <?xml version="1.0" encoding="utf-8"?>
            <package xmlns="http://www.idpf.org/2007/opf" version="2.0" unique-identifier="BookId">
              <metadata xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:opf="http://www.idpf.org/2007/opf">
                <dc:title>Old Title</dc:title>
                <dc:creator>Old Author</dc:creator>
                <dc:publisher>Old Publisher</dc:publisher>
                <dc:language>fr</dc:language>
                <dc:identifier id="BookId">urn:uuid:0000</dc:identifier>
                <dc:identifier opf:scheme="ISBN">0000000000</dc:identifier>
                <meta name="cover" content="old-cover"/>
              </metadata>
              <manifest>
                <item id="old-cover" href="old.jpg" media-type="image/jpeg" properties="cover-image"/>
                <item id="chap1" href="chap1.xhtml" media-type="application/xhtml+xml"/>
              </manifest>
              <spine>
                <itemref idref="chap1"/>
              </spine>
            </package>
            XML;

        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('mimetype', 'application/epub+zip');
        $zip->addFromString('META-INF/container.xml', $container);
        $zip->addFromString('OEBPS/content.opf', $opf);
        $zip->addFromString('OEBPS/old.jpg', 'OLDCOVER');
        $zip->addFromString('OEBPS/chap1.xhtml', '<html><body>x</body></html>');
        $zip->close();
    }

    /** @return array{0: \DOMDocument, 1: \DOMXPath} */
    private function openOpf(string $path): array
    {
        $zip = new \ZipArchive();
        $zip->open($path);
        $xml = $zip->getFromName('OEBPS/content.opf');
        $zip->close();
        self::assertIsString($xml);

        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('opf', self::OPF_NS);
        $xpath->registerNamespace('dc', self::DC_NS);

        return [$dom, $xpath];
    }

    private function text(\DOMXPath $xpath, string $query): string
    {
        $nodes = $xpath->query($query);
        self::assertNotFalse($nodes);
        self::assertGreaterThan(0, $nodes->length, "No node for {$query}");
        return trim($nodes->item(0)->textContent);
    }

    private function firstText(\DOMXPath $xpath, string $query): ?string
    {
        $nodes = $xpath->query($query);
        return ($nodes !== false && $nodes->length > 0) ? trim($nodes->item(0)->textContent) : null;
    }
}
