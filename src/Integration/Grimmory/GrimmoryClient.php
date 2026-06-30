<?php

declare(strict_types=1);

namespace App\Integration\Grimmory;

use App\Entity\Integration;
use App\Integration\Grimmory\Dto\BookSummary;
use App\Integration\Grimmory\Dto\LibraryEntry;
use App\Support\AudioFormat;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Base URL is the Komga server root; `/api/v1/...` is appended here.
 * Auth is HTTP Basic only — Komga doesn't issue bearer tokens for this surface.
 */
final class GrimmoryClient
{
    private const API_PREFIX = '/api/v1';

    /** Komga pages cap at 1000 in practice; 100 is friendlier to the server. */
    private const PAGE_SIZE = 100;

    /** Safety cap so a broken `last:false` response can't loop forever. */
    private const MAX_PAGES = 1000;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @return list<LibraryEntry>
     *
     * @throws GrimmoryException
     */
    public function discoverLibraries(Integration $integration): array
    {
        $data = $this->getJson($integration, '/libraries');
        if (!is_array($data)) {
            throw new GrimmoryException('Unexpected /libraries response: expected JSON array.');
        }
        $out = [];
        foreach ($data as $row) {
            if (!is_array($row) || !isset($row['id'], $row['name'])) {
                continue;
            }
            $out[] = new LibraryEntry(id: (string) $row['id'], name: (string) $row['name']);
        }
        return $out;
    }

    /**
     * Empty/null $libraryIds means "every library".
     *
     * @param list<string>|null $libraryIds
     * @return iterable<BookSummary>
     *
     * @throws GrimmoryException
     */
    public function listBooks(Integration $integration, ?array $libraryIds = null): iterable
    {
        $baseQuery = [
            'size' => self::PAGE_SIZE,
            'sort' => 'createdDate,desc',
        ];
        if ($libraryIds !== null && $libraryIds !== []) {
            // Komga accepts CSV in `library_id`.
            $baseQuery['library_id'] = implode(',', $libraryIds);
        }

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $body = $this->getJson($integration, '/books', $baseQuery + ['page' => $page]);
            if (!is_array($body) || !isset($body['content']) || !is_array($body['content'])) {
                throw new GrimmoryException('Unexpected /books response shape.');
            }
            foreach ($body['content'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                yield $this->bookSummaryFromRow($row);
            }
            if (($body['last'] ?? true) === true || $body['content'] === []) {
                return;
            }
        }
    }

    /**
     * Dev diagnostic for the Development → Komga Inspector. Returns the raw media/format detail
     * per book (one page, capped at $limit) so you can see what {@see formatFromRow()} resolves
     * and how it classifies each owned file: 'audiobook', 'book', or 'unknown' (no derivable
     * format). This is the exact `format` persisted to {@see \App\Entity\Book::$format} and used
     * by the Browse "downloaded" badge.
     *
     * @param list<string>|null $libraryIds
     * @return list<array{id: string, title: string, author: ?string, url: ?string, media_type: ?string, media_profile: ?string, format: ?string, classification: string}>
     *
     * @throws GrimmoryException
     */
    public function fetchBooksDebug(Integration $integration, ?array $libraryIds = null, int $limit = 50): array
    {
        $baseQuery = ['size' => max(1, min($limit, self::PAGE_SIZE)), 'sort' => 'createdDate,desc'];
        if ($libraryIds !== null && $libraryIds !== []) {
            $baseQuery['library_id'] = implode(',', $libraryIds);
        }

        $body = $this->getJson($integration, '/books', $baseQuery + ['page' => 0]);
        if (!is_array($body) || !isset($body['content']) || !is_array($body['content'])) {
            throw new GrimmoryException('Unexpected /books response shape.');
        }

        $out = [];
        foreach ($body['content'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $metadata = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];
            $authors = is_array($metadata['authors'] ?? null) ? $metadata['authors'] : [];
            $authorNames = [];
            foreach ($authors as $a) {
                if (is_array($a) && !empty($a['name'])) {
                    $authorNames[] = (string) $a['name'];
                }
            }
            $media = is_array($row['media'] ?? null) ? $row['media'] : [];
            $format = $this->formatFromRow($row);

            $out[] = [
                'id'            => (string) ($row['id'] ?? ''),
                'title'         => (string) ($metadata['title'] ?? $row['name'] ?? '(untitled)'),
                'author'        => $authorNames === [] ? null : implode(', ', $authorNames),
                'url'           => isset($row['url']) ? (string) $row['url'] : null,
                'media_type'    => isset($media['mediaType']) ? (string) $media['mediaType'] : null,
                'media_profile' => isset($media['mediaProfile']) ? (string) $media['mediaProfile'] : null,
                'format'        => $format,
                'classification' => $format === null ? 'unknown' : (AudioFormat::isAudio($format) ? 'audiobook' : 'book'),
            ];
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /** @throws GrimmoryException */
    public function ping(Integration $integration): void
    {
        $this->getJson($integration, '/libraries');
    }

    /**
     * @return array{
     *     title: ?string,
     *     author: ?string,
     *     publisher: ?string,
     *     publishedDate: ?string,
     *     language: ?string,
     *     isbn: ?string,
     *     description: ?string,
     *     genres: list<string>,
     *     series: ?string,
     *     seriesIndex: ?string,
     *     seriesTotal: ?int,
     * }
     *
     * @throws GrimmoryException
     */
    public function fetchBookMetadata(Integration $integration, string $externalId): array
    {
        $row = $this->getJson($integration, '/books/' . rawurlencode($externalId));
        if (!is_array($row)) {
            throw new GrimmoryException('Unexpected /books/{id} response shape.');
        }
        $meta = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];
        $authors = is_array($meta['authors'] ?? null) ? $meta['authors'] : [];
        $authorNames = [];
        foreach ($authors as $a) {
            if (is_array($a) && !empty($a['name'])) {
                $authorNames[] = (string) $a['name'];
            }
        }
        $tags = is_array($meta['tags'] ?? null) ? $meta['tags'] : [];
        $genres = [];
        foreach ($tags as $t) {
            if (is_string($t) && $t !== '') {
                $genres[] = $t;
            }
        }

        // Komga returns series book count on the series record, not the book record.
        $seriesTotal = null;
        $seriesId = $row['seriesId'] ?? null;
        if (is_string($seriesId) && $seriesId !== '') {
            try {
                $series = $this->getJson($integration, '/series/' . rawurlencode($seriesId));
                if (is_array($series)) {
                    $count = $series['booksCount'] ?? ($series['metadata']['totalBookCount'] ?? null);
                    if (is_int($count)) {
                        $seriesTotal = $count;
                    } elseif (is_string($count) && ctype_digit($count)) {
                        $seriesTotal = (int) $count;
                    }
                }
            } catch (GrimmoryException) {
                // Series-count lookup is best-effort; the popup still renders without it.
            }
        }

        return [
            'title'         => isset($meta['title']) ? (string) $meta['title'] : (isset($row['name']) ? (string) $row['name'] : null),
            'author'        => $authorNames === [] ? null : implode(', ', $authorNames),
            'publisher'     => !empty($meta['publisher']) ? (string) $meta['publisher'] : null,
            'publishedDate' => !empty($meta['releaseDate']) ? (string) $meta['releaseDate'] : null,
            'language'      => !empty($meta['language']) ? (string) $meta['language'] : null,
            'isbn'          => \App\Repository\BookRepository::normalizeIsbn($meta['isbn'] ?? null),
            'description'   => !empty($meta['summary']) ? (string) $meta['summary'] : null,
            'genres'        => $genres,
            'series'        => !empty($row['seriesTitle']) ? (string) $row['seriesTitle'] : null,
            'seriesIndex'   => isset($row['number']) ? (string) $row['number'] : null,
            'seriesTotal'   => $seriesTotal,
        ];
    }

    /**
     * @param array<string, mixed> $query
     */
    private function getJson(Integration $integration, string $path, array $query = []): mixed
    {
        $base = $integration->getBaseUrl();
        if ($base === null || $base === '') {
            throw new GrimmoryException('Grimmory (Komga) server URL is not configured.');
        }
        $url = rtrim($base, '/') . self::API_PREFIX . $path;

        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => 30,
                'auth_basic' => $this->basicAuth($integration),
                'headers' => ['Accept' => 'application/json'],
                'query' => $query,
            ]);
            $status = $response->getStatusCode();
            if ($status === 401 || $status === 403) {
                throw new GrimmoryException(sprintf('Grimmory rejected our credentials (HTTP %d).', $status));
            }
            if ($status >= 400) {
                throw new GrimmoryException(sprintf('Grimmory returned HTTP %d for %s', $status, $path));
            }
            return $response->toArray(false);
        } catch (TransportException $e) {
            throw new GrimmoryException('Could not reach Grimmory: ' . $e->getMessage(), previous: $e);
        } catch (HttpExceptionInterface $e) {
            throw new GrimmoryException('Could not parse Grimmory response: ' . $e->getMessage(), previous: $e);
        }
    }

    /** @return array{0: string, 1: string} */
    private function basicAuth(Integration $integration): array
    {
        $creds = $integration->getCredentials();
        return [(string) ($creds['username'] ?? ''), (string) ($creds['password'] ?? '')];
    }

    /** @param array<string, mixed> $row */
    private function bookSummaryFromRow(array $row): BookSummary
    {
        $metadata = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];
        $authors = is_array($metadata['authors'] ?? null) ? $metadata['authors'] : [];

        $authorNames = [];
        foreach ($authors as $a) {
            if (is_array($a) && !empty($a['name'])) {
                $authorNames[] = (string) $a['name'];
            }
        }

        return new BookSummary(
            externalId: (string) $row['id'],
            title: (string) ($metadata['title'] ?? $row['name'] ?? '(untitled)'),
            author: $authorNames === [] ? null : implode(', ', $authorNames),
            series: !empty($row['seriesTitle']) ? (string) $row['seriesTitle'] : null,
            seriesIndex: isset($row['number']) ? (string) $row['number'] : null,
            externalUrl: !empty($row['url']) ? (string) $row['url'] : null,
            libraryId: isset($row['libraryId']) ? (string) $row['libraryId'] : null,
            isbn: \App\Repository\BookRepository::normalizeIsbn($metadata['isbn'] ?? null),
            addedAt: $this->parseTimestamp($row['created'] ?? null),
            lastModifiedAt: $this->parseTimestamp($row['lastModified'] ?? null),
            format: $this->formatFromRow($row),
        );
    }

    /**
     * The owned file's format token, lowercased (e.g. `epub`, `pdf`, `m4b`, `mp3`). Komga's
     * book `url` is the on-disk file path, so its extension is the most reliable cross-format
     * signal; fall back to the media MIME subtype when no extension is present. This is what
     * lets the app distinguish an owned audiobook from an ebook (see {@see \App\Support\AudioFormat}).
     *
     * @param array<string, mixed> $row
     */
    private function formatFromRow(array $row): ?string
    {
        $url = is_string($row['url'] ?? null) ? $row['url'] : '';
        $path = parse_url($url, PHP_URL_PATH);
        $ext = strtolower(pathinfo(is_string($path) && $path !== '' ? $path : $url, PATHINFO_EXTENSION));
        if ($ext !== '') {
            return substr($ext, 0, 32);
        }

        // Fall back to Komga's media MIME (e.g. "application/epub+zip" -> "epub", "audio/mpeg" -> "mpeg").
        $media = is_array($row['media'] ?? null) ? $row['media'] : [];
        $mime = is_string($media['mediaType'] ?? null) ? strtolower($media['mediaType']) : '';
        if ($mime !== '' && preg_match('~/(?:x-)?([a-z0-9.+-]+)~', $mime, $m)) {
            $sub = preg_replace('~\+.*$~', '', $m[1]); // strip "+zip"/"+xml" suffix
            if ($sub !== '' && $sub !== null) {
                return substr($sub, 0, 32);
            }
        }
        return null;
    }

    private function parseTimestamp(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
