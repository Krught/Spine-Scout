<?php

declare(strict_types=1);

namespace App\Download\Metadata;

use App\Entity\Book;

/**
 * Rewrites an EPUB's embedded metadata in place with SpineSCOUT's stored values.
 *
 * An EPUB is a ZIP whose package document (the OPF, located via
 * META-INF/container.xml) carries a Dublin Core <metadata> block. We strip the
 * Dublin Core elements we own (title, creator, publisher, language, description,
 * date, and any ISBN identifiers) and re-add them from the Book, then — when a
 * cover image is supplied — inject it as the canonical cover (EPUB3
 * properties="cover-image" + EPUB2 <meta name="cover">), leaving everything else
 * untouched.
 *
 * Throws on any structural problem (not a zip, no container.xml, no OPF, malformed
 * XML) so the caller can treat injection as best-effort and still ship the file.
 */
final class EpubMetadataWriter
{
    private const OPF_NS       = 'http://www.idpf.org/2007/opf';
    private const DC_NS        = 'http://purl.org/dc/elements/1.1/';
    private const CONTAINER_NS = 'urn:oasis:names:tc:opendocument:xmlns:container';

    private const COVER_ITEM_ID = 'spinescout-cover-image';

    /**
     * @param array{0: string, 1: string}|null $cover [raw image bytes, mime type]; null skips the cover step
     */
    public function write(string $epubPath, Book $book, ?array $cover = null): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($epubPath) !== true) {
            throw new \RuntimeException('Not a readable ZIP/EPUB: ' . $epubPath);
        }

        try {
            $opfPath = $this->locateOpf($zip);
            $opfXml = $zip->getFromName($opfPath);
            if ($opfXml === false) {
                throw new \RuntimeException('OPF package document missing: ' . $opfPath);
            }

            $dom = new \DOMDocument();
            $dom->preserveWhiteSpace = false;
            $dom->formatOutput = true;
            if (@$dom->loadXML($opfXml) === false) {
                throw new \RuntimeException('OPF is not well-formed XML.');
            }

            $xpath = new \DOMXPath($dom);
            $xpath->registerNamespace('opf', self::OPF_NS);
            $xpath->registerNamespace('dc', self::DC_NS);

            $metadata = $this->firstNode($xpath, '//opf:metadata');
            if (!$metadata instanceof \DOMElement) {
                throw new \RuntimeException('OPF has no <metadata> element.');
            }

            $this->rewriteDublinCore($dom, $xpath, $metadata, $book);

            if ($cover !== null) {
                $this->injectCover($zip, $dom, $xpath, $metadata, $opfPath, $cover[0], $cover[1]);
            }

            $newXml = $dom->saveXML();
            if ($newXml === false || $zip->addFromString($opfPath, $newXml) === false) {
                throw new \RuntimeException('Failed to write modified OPF back into the EPUB.');
            }
        } finally {
            // close() flushes pending writes; on the throw path it discards them.
            $zip->close();
        }
    }

    /** Resolve the OPF package path from META-INF/container.xml. */
    private function locateOpf(\ZipArchive $zip): string
    {
        $containerXml = $zip->getFromName('META-INF/container.xml');
        if ($containerXml === false) {
            throw new \RuntimeException('EPUB missing META-INF/container.xml.');
        }

        $dom = new \DOMDocument();
        if (@$dom->loadXML($containerXml) === false) {
            throw new \RuntimeException('META-INF/container.xml is not well-formed.');
        }
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('c', self::CONTAINER_NS);

        $rootfile = $this->firstNode($xpath, '//c:rootfile[@full-path]');
        $fullPath = $rootfile instanceof \DOMElement ? trim($rootfile->getAttribute('full-path')) : '';
        if ($fullPath === '') {
            throw new \RuntimeException('container.xml does not point at an OPF rootfile.');
        }

        return $fullPath;
    }

    /**
     * Strip the Dublin Core elements we own and re-add them from the Book. ISBN
     * identifiers are replaced too, but never the one referenced by the package's
     * unique-identifier (removing it would invalidate the EPUB).
     */
    private function rewriteDublinCore(\DOMDocument $dom, \DOMXPath $xpath, \DOMElement $metadata, Book $book): void
    {
        foreach (['title', 'creator', 'publisher', 'language', 'description', 'date'] as $tag) {
            $this->removeNodes($xpath, 'dc:' . $tag, $metadata);
        }
        $this->removeIsbnIdentifiers($xpath, $metadata);

        $this->appendDc($dom, $metadata, 'title', $book->getTitle());
        $this->appendDc($dom, $metadata, 'creator', $book->getAuthor());
        $this->appendDc($dom, $metadata, 'publisher', $book->getPublisher());
        $this->appendDc($dom, $metadata, 'language', $book->getLanguage());
        $this->appendDc($dom, $metadata, 'description', $book->getDescription());
        $this->appendDc($dom, $metadata, 'date', $book->getPublishedDate());

        $isbn = $book->getIsbn() ?? ($book->getIsbns()[0] ?? null);
        if ($isbn !== null && $isbn !== '') {
            $identifier = $dom->createElementNS(self::DC_NS, 'dc:identifier');
            $identifier->setAttributeNS(self::OPF_NS, 'opf:scheme', 'ISBN');
            $identifier->appendChild($dom->createTextNode($isbn));
            $metadata->appendChild($identifier);
        }
    }

    /** Remove ISBN-schemed/urn:isbn identifiers, sparing the package unique-identifier. */
    private function removeIsbnIdentifiers(\DOMXPath $xpath, \DOMElement $metadata): void
    {
        $package = $this->firstNode($xpath, '/opf:package');
        $uniqueId = $package instanceof \DOMElement ? trim($package->getAttribute('unique-identifier')) : '';

        foreach (iterator_to_array($xpath->query('dc:identifier', $metadata) ?: []) as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            if ($uniqueId !== '' && $node->getAttribute('id') === $uniqueId) {
                continue;
            }
            $scheme = strtolower($node->getAttributeNS(self::OPF_NS, 'scheme'));
            $value = strtolower(trim($node->textContent));
            if ($scheme === 'isbn' || str_contains($value, 'isbn')) {
                $node->parentNode?->removeChild($node);
            }
        }
    }

    private function appendDc(\DOMDocument $dom, \DOMElement $metadata, string $tag, ?string $value): void
    {
        if ($value === null || trim($value) === '') {
            return;
        }
        $el = $dom->createElementNS(self::DC_NS, 'dc:' . $tag);
        $el->appendChild($dom->createTextNode($value));
        $metadata->appendChild($el);
    }

    /**
     * Add the cover image to the EPUB and make it canonical: a manifest item with
     * properties="cover-image" (EPUB3) plus a <meta name="cover"> pointer (EPUB2),
     * after demoting any prior cover markers.
     */
    private function injectCover(
        \ZipArchive $zip,
        \DOMDocument $dom,
        \DOMXPath $xpath,
        \DOMElement $metadata,
        string $opfPath,
        string $bytes,
        string $mime,
    ): void {
        $manifest = $this->firstNode($xpath, '//opf:manifest');
        if (!$manifest instanceof \DOMElement) {
            return; // No manifest: can't reference a cover; leave the DC rewrite as-is.
        }

        $ext = match ($mime) {
            'image/png' => 'png',
            'image/gif' => 'gif',
            default     => 'jpg',
        };
        $href = 'spinescout-cover.' . $ext;
        $opfDir = str_contains($opfPath, '/') ? substr($opfPath, 0, strrpos($opfPath, '/')) : '';
        $imageEntry = ($opfDir === '' ? '' : $opfDir . '/') . $href;
        if ($zip->addFromString($imageEntry, $bytes) === false) {
            throw new \RuntimeException('Failed to add cover image to EPUB.');
        }

        // Demote existing cover-image properties so ours is the only one.
        foreach (iterator_to_array($xpath->query('//opf:manifest/opf:item[@properties]') ?: []) as $item) {
            if (!$item instanceof \DOMElement) {
                continue;
            }
            $props = preg_split('/\s+/', trim($item->getAttribute('properties'))) ?: [];
            $kept = array_values(array_filter($props, static fn (string $p) => $p !== '' && $p !== 'cover-image'));
            if ($kept === []) {
                $item->removeAttribute('properties');
            } else {
                $item->setAttribute('properties', implode(' ', $kept));
            }
        }
        // Drop a prior item that already used our reserved id, then add the fresh one.
        foreach (iterator_to_array($xpath->query('//opf:manifest/opf:item[@id="' . self::COVER_ITEM_ID . '"]') ?: []) as $dupe) {
            $dupe->parentNode?->removeChild($dupe);
        }

        $item = $dom->createElementNS(self::OPF_NS, 'item');
        $item->setAttribute('id', self::COVER_ITEM_ID);
        $item->setAttribute('href', $href);
        $item->setAttribute('media-type', $mime);
        $item->setAttribute('properties', 'cover-image');
        $manifest->appendChild($item);

        // EPUB2 pointer: replace any existing <meta name="cover">.
        foreach (iterator_to_array($xpath->query('opf:meta[@name="cover"]', $metadata) ?: []) as $meta) {
            $meta->parentNode?->removeChild($meta);
        }
        $meta = $dom->createElementNS(self::OPF_NS, 'meta');
        $meta->setAttribute('name', 'cover');
        $meta->setAttribute('content', self::COVER_ITEM_ID);
        $metadata->appendChild($meta);
    }

    private function firstNode(\DOMXPath $xpath, string $query): ?\DOMNode
    {
        $nodes = $xpath->query($query);
        return ($nodes !== false && $nodes->length > 0) ? $nodes->item(0) : null;
    }

    private function removeNodes(\DOMXPath $xpath, string $query, \DOMElement $context): void
    {
        foreach (iterator_to_array($xpath->query($query, $context) ?: []) as $node) {
            $node->parentNode?->removeChild($node);
        }
    }
}
