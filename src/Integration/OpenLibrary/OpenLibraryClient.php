<?php

declare(strict_types=1);

namespace App\Integration\OpenLibrary;

use App\Integration\Hardcover\Dto\TrendingBook;
use App\Repository\BookRepository;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class OpenLibraryClient
{
    private const TRENDING_URL = 'https://openlibrary.org/trending/daily.json';
    private const SEARCH_URL   = 'https://openlibrary.org/search.json';
    private const COVERS_BASE  = 'https://covers.openlibrary.org/b/id/';
    private const WORKS_BASE   = 'https://openlibrary.org';
    private const USER_AGENT   = 'SpineScout/1.0 (+https://spinescout.local)';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @return list<TrendingBook>
     *
     * @throws OpenLibraryException
     */
    public function fetchTrending(int $limit = 25): array
    {
        try {
            $response = $this->httpClient->request('GET', self::TRENDING_URL, [
                'timeout' => 30,
                'query' => ['limit' => $limit],
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => self::USER_AGENT,
                ],
            ]);
            $status = $response->getStatusCode();
            if ($status >= 400) {
                throw new OpenLibraryException(sprintf('Open Library returned HTTP %d.', $status));
            }
            $body = $response->toArray(false);
        } catch (TransportException $e) {
            throw new OpenLibraryException('Could not reach Open Library: ' . $e->getMessage(), previous: $e);
        } catch (HttpExceptionInterface $e) {
            throw new OpenLibraryException('Could not parse Open Library response: ' . $e->getMessage(), previous: $e);
        }

        $works = $body['works'] ?? null;
        if (!is_array($works)) {
            throw new OpenLibraryException('Unexpected trending payload: missing `works`.');
        }

        $out = [];
        foreach ($works as $row) {
            if (!is_array($row) || empty($row['title'])) {
                continue;
            }
            $authors = is_array($row['author_name'] ?? null) ? $row['author_name'] : [];
            $coverId = $row['cover_i'] ?? null;
            $workKey = isset($row['key']) ? (string) $row['key'] : null;

            $out[] = new TrendingBook(
                title: (string) $row['title'],
                author: $authors === [] ? null : implode(', ', array_slice($authors, 0, 3)),
                coverUrl: is_int($coverId) || (is_string($coverId) && ctype_digit($coverId))
                    ? self::COVERS_BASE . $coverId . '-M.jpg'
                    : null,
                externalUrl: $workKey !== null ? self::WORKS_BASE . $workKey : null,
                isbns: $this->extractIsbns($row),
            );
        }
        return $out;
    }

    /**
     * @return list<TrendingBook>
     *
     * @throws OpenLibraryException
     */
    public function searchBooks(string $query, int $limit = 50, int $page = 1, string $type = 'title'): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        // OpenLibrary's search.json accepts field-prefixed Lucene-style queries:
        // `title:`, `author:`, `subject:`. `series:` and `publisher:` are tolerated
        // (matched against indexed editions); unknown fields degrade to full-text.
        $fieldMap = [
            'title' => 'title',
            'author' => 'author',
            'genre' => 'subject',
            'series' => 'series',
            'publisher' => 'publisher',
        ];
        $field = $fieldMap[$type] ?? 'title';
        $needsQuotes = preg_match('/\s/', $query) === 1;
        $q = $field . ':' . ($needsQuotes ? '"' . str_replace('"', '', $query) . '"' : $query);
        try {
            $response = $this->httpClient->request('GET', self::SEARCH_URL, [
                'timeout' => 30,
                'query' => [
                    'q' => $q,
                    'limit' => max(1, min(100, $limit)),
                    'page' => max(1, $page),
                    'fields' => 'key,title,author_name,cover_i,isbn',
                ],
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => self::USER_AGENT,
                ],
            ]);
            $status = $response->getStatusCode();
            if ($status >= 400) {
                throw new OpenLibraryException(sprintf('Open Library returned HTTP %d.', $status));
            }
            $body = $response->toArray(false);
        } catch (TransportException $e) {
            throw new OpenLibraryException('Could not reach Open Library: ' . $e->getMessage(), previous: $e);
        } catch (HttpExceptionInterface $e) {
            throw new OpenLibraryException('Could not parse Open Library response: ' . $e->getMessage(), previous: $e);
        }

        $docs = $body['docs'] ?? null;
        if (!is_array($docs)) {
            throw new OpenLibraryException('Unexpected search payload: missing `docs`.');
        }

        $out = [];
        foreach ($docs as $row) {
            if (!is_array($row) || empty($row['title'])) {
                continue;
            }
            $authors = is_array($row['author_name'] ?? null) ? $row['author_name'] : [];
            $coverId = $row['cover_i'] ?? null;
            $workKey = isset($row['key']) ? (string) $row['key'] : null;

            $out[] = new TrendingBook(
                title: (string) $row['title'],
                author: $authors === [] ? null : implode(', ', array_slice($authors, 0, 3)),
                coverUrl: is_int($coverId) || (is_string($coverId) && ctype_digit($coverId))
                    ? self::COVERS_BASE . $coverId . '-M.jpg'
                    : null,
                externalUrl: $workKey !== null ? self::WORKS_BASE . $workKey : null,
                isbns: $this->extractIsbns($row),
            );
        }
        return $out;
    }

    /**
     * Edition fields come from the most popular edition; description and subjects from the work.
     *
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
     * @throws OpenLibraryException
     */
    public function fetchBookMetadataByKey(string $workKey): array
    {
        $work = $this->getJson(self::WORKS_BASE . $this->normalizeKey($workKey) . '.json');
        if (!is_array($work)) {
            throw new OpenLibraryException('Unexpected work payload.');
        }

        $title = !empty($work['title']) ? (string) $work['title'] : null;
        $description = $this->extractDescription($work['description'] ?? null);

        $subjects = is_array($work['subjects'] ?? null) ? $work['subjects'] : [];
        $genres = [];
        foreach ($subjects as $s) {
            if (is_string($s) && $s !== '') {
                $genres[] = $s;
            }
        }
        // Open Library subject lists are noisy; cap to keep the popup readable.
        $genres = array_values(array_slice(array_unique($genres), 0, 12));

        $author = null;
        $authors = is_array($work['authors'] ?? null) ? $work['authors'] : [];
        $names = [];
        foreach ($authors as $a) {
            $key = $a['author']['key'] ?? null;
            if (!is_string($key) || $key === '') {
                continue;
            }
            try {
                $auth = $this->getJson(self::WORKS_BASE . $key . '.json');
                if (is_array($auth) && !empty($auth['name'])) {
                    $names[] = (string) $auth['name'];
                }
            } catch (OpenLibraryException) {
                // Skip authors we can't resolve; still return what we have.
            }
        }
        if ($names !== []) {
            $author = implode(', ', array_slice($names, 0, 3));
        }

        $publisher = null;
        $publishedDate = !empty($work['first_publish_date']) ? (string) $work['first_publish_date'] : null;
        $language = null;
        $isbn = null;
        try {
            $editions = $this->getJson(self::WORKS_BASE . $this->normalizeKey($workKey) . '/editions.json?limit=10');
            $entries = is_array($editions['entries'] ?? null) ? $editions['entries'] : [];
            foreach ($entries as $e) {
                if (!is_array($e)) {
                    continue;
                }
                if ($publisher === null && is_array($e['publishers'] ?? null) && isset($e['publishers'][0])) {
                    $publisher = (string) $e['publishers'][0];
                }
                if ($publishedDate === null && !empty($e['publish_date'])) {
                    $publishedDate = (string) $e['publish_date'];
                }
                if ($language === null && is_array($e['languages'] ?? null) && isset($e['languages'][0]['key'])) {
                    // "/languages/eng" → "eng"
                    $language = basename((string) $e['languages'][0]['key']);
                }
                if ($isbn === null) {
                    foreach (['isbn_13', 'isbn_10'] as $field) {
                        if (is_array($e[$field] ?? null) && isset($e[$field][0])) {
                            $isbn = BookRepository::normalizeIsbn($e[$field][0]);
                            if ($isbn !== null) {
                                break;
                            }
                        }
                    }
                }
                if ($publisher !== null && $publishedDate !== null && $language !== null && $isbn !== null) {
                    break;
                }
            }
        } catch (OpenLibraryException) {
            // Editions endpoint is best-effort; the popup still renders with work-level fields.
        }

        return [
            'title'         => $title,
            'author'        => $author,
            'publisher'     => $publisher,
            'publishedDate' => $publishedDate,
            'language'      => $language,
            'isbn'          => $isbn,
            'description'   => $description,
            'genres'        => $genres,
            // Open Library doesn't surface series consistently on /works; leave empty.
            'series'        => null,
            'seriesIndex'   => null,
            'seriesTotal'   => null,
        ];
    }

    /** Open Library's `description` is sometimes a string, sometimes `{type, value}`. */
    private function extractDescription(mixed $raw): ?string
    {
        if (is_string($raw) && $raw !== '') {
            return $raw;
        }
        if (is_array($raw) && !empty($raw['value']) && is_string($raw['value'])) {
            return $raw['value'];
        }
        return null;
    }

    /** Accept `OL123W`, `/works/OL123W`, or a full URL; return `/works/OL123W`. */
    private function normalizeKey(string $key): string
    {
        if (str_starts_with($key, 'http')) {
            $path = parse_url($key, PHP_URL_PATH) ?: '';
            $key = $path;
        }
        $key = '/' . ltrim($key, '/');
        if (!str_starts_with($key, '/works/')) {
            $key = '/works/' . ltrim($key, '/');
        }
        return $key;
    }

    private function getJson(string $url): mixed
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => 30,
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => self::USER_AGENT,
                ],
            ]);
            $status = $response->getStatusCode();
            if ($status >= 400) {
                throw new OpenLibraryException(sprintf('Open Library returned HTTP %d for %s.', $status, $url));
            }
            return $response->toArray(false);
        } catch (TransportException $e) {
            throw new OpenLibraryException('Could not reach Open Library: ' . $e->getMessage(), previous: $e);
        } catch (HttpExceptionInterface $e) {
            throw new OpenLibraryException('Could not parse Open Library response: ' . $e->getMessage(), previous: $e);
        }
    }

    /**
     * Tolerates both `availability.isbn*` and top-level `isbn[]` — trending payload is undocumented.
     *
     * @param array<string, mixed> $row
     * @return list<string>
     */
    private function extractIsbns(array $row): array
    {
        $candidates = [];
        $availability = $row['availability'] ?? null;
        if (is_array($availability)) {
            foreach (['isbn', 'isbn_10', 'isbn_13'] as $field) {
                $v = $availability[$field] ?? null;
                if (is_string($v)) {
                    $candidates[] = $v;
                }
            }
        }
        if (is_array($row['isbn'] ?? null)) {
            foreach ($row['isbn'] as $v) {
                if (is_string($v)) {
                    $candidates[] = $v;
                }
            }
        }
        $seen = [];
        foreach ($candidates as $c) {
            $norm = BookRepository::normalizeIsbn($c);
            if ($norm !== null) {
                $seen[$norm] = true;
            }
        }
        return array_map(static fn ($k) => (string) $k, array_keys($seen));
    }
}
