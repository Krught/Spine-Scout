<?php

declare(strict_types=1);

namespace App\Integration\Hardcover;

use App\Entity\Integration;
use App\Integration\Hardcover\Dto\PopularAuthor;
use App\Integration\Hardcover\Dto\TrendingBook;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Server-side GraphQL client; bearer-token only. See https://docs.hardcover.app/api/.
 */
final class HardcoverClient
{
    private const ENDPOINT   = 'https://api.hardcover.app/v1/graphql';
    private const USER_AGENT = 'SpineScout/1.0 (+https://spinescout.local)';

    private const TRENDING_IDS_QUERY_TEMPLATE = <<<'GQL'
        query SpineScoutTrendingIds($limit: Int!) {
          books_trending(limit: $limit, offset: 0, from: "%FROM%", to: "%TO%") {
            ids
            error
          }
        }
        GQL;

    private const BOOKS_BY_IDS_QUERY = <<<'GQL'
        query SpineScoutBooksByIds($ids: [Int!]!) {
          books(where: {id: {_in: $ids}}) {
            id
            title
            slug
            cached_image
            cached_contributors
            editions(limit: 200, where: {_or: [{isbn_10: {_is_null: false}}, {isbn_13: {_is_null: false}}]}) {
              isbn_10
              isbn_13
              physical_format
              users_count
              language { code3 }
              country { code2 }
            }
          }
        }
        GQL;

    /** Shared field set for `books(...)` discovery queries; matches BOOKS_BY_IDS_QUERY's projection. */
    private const BOOKS_FIELDS = <<<'GQL'
        id
        title
        slug
        cached_image
        cached_contributors
        editions(limit: 200, where: {_or: [{isbn_10: {_is_null: false}}, {isbn_13: {_is_null: false}}]}) {
          isbn_10
          isbn_13
          physical_format
          users_count
          language { code3 }
          country { code2 }
        }
        GQL;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @return list<TrendingBook>
     *
     * @throws HardcoverException
     */
    public function fetchTrending(Integration $integration, int $limit = 25): array
    {
        // `books_trending` returns only ids; book records come from a follow-up `books(where:_in)`
        // query, re-ordered here because Hasura doesn't preserve `_in` order.
        // Dates are baked into the query string — Hardcover's schema rejects variable substitution
        // for from/to (returns "missing required field 'from'"). Window matches Hardcover's web UI.
        $to = new \DateTimeImmutable('today');
        $from = $to->modify('-30 days');
        $idsQuery = strtr(self::TRENDING_IDS_QUERY_TEMPLATE, [
            '%FROM%' => $from->format('Y-m-d'),
            '%TO%' => $to->format('Y-m-d'),
        ]);
        $idsData = $this->graphql($integration, $idsQuery, ['limit' => $limit]);
        $trending = $idsData['books_trending'] ?? null;
        if (!is_array($trending) || !isset($trending['ids']) || !is_array($trending['ids'])) {
            throw new HardcoverException('Unexpected trending payload: missing books_trending.ids');
        }
        if (!empty($trending['error'])) {
            throw new HardcoverException('Hardcover trending error: ' . $trending['error']);
        }
        $ids = array_values(array_filter(array_map('intval', $trending['ids'])));
        if ($ids === []) {
            return [];
        }

        $booksData = $this->graphql($integration, self::BOOKS_BY_IDS_QUERY, ['ids' => $ids]);
        $rows = $booksData['books'] ?? null;
        if (!is_array($rows)) {
            throw new HardcoverException('Unexpected books payload: missing `books`.');
        }

        $byId = [];
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['id'])) {
                $byId[(int) $row['id']] = $row;
            }
        }

        $out = [];
        foreach ($ids as $id) {
            $row = $byId[$id] ?? null;
            if ($row === null || empty($row['title'])) {
                continue;
            }
            $out[] = new TrendingBook(
                title: (string) $row['title'],
                author: $this->extractAuthor($row['cached_contributors'] ?? null),
                coverUrl: $this->extractCoverUrl($row['cached_image'] ?? null),
                externalUrl: !empty($row['slug']) ? 'https://hardcover.app/books/' . $row['slug'] : null,
                isbns: $this->extractIsbns($row['editions'] ?? null, $integration->getHardcoverEditionPreferences()),
            );
        }
        return $out;
    }

    /**
     * @return list<TrendingBook>
     *
     * @throws HardcoverException
     */
    public function fetchNewReleases(Integration $integration, int $limit = 25): array
    {
        $today = new \DateTimeImmutable('today');
        $from = $today->modify('-90 days')->format('Y-m-d');
        $to = $today->format('Y-m-d');
        $query = '
            query SpineScoutNewReleases($limit: Int!, $from: date!, $to: date!) {
              books(
                where: {release_date: {_gte: $from, _lte: $to}, users_count: {_gt: 0}}
                order_by: {users_count: desc_nulls_last}
                limit: $limit
              ) { ' . self::BOOKS_FIELDS . ' }
            }
        ';
        return $this->mapBooks(
            $this->graphql($integration, $query, ['limit' => $limit, 'from' => $from, 'to' => $to]),
            $integration,
        );
    }

    /**
     * Ordered by users_count (Hardcover's "want to read" + reader proxy).
     *
     * @return list<TrendingBook>
     *
     * @throws HardcoverException
     */
    public function fetchUpcoming(Integration $integration, int $limit = 25): array
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $query = '
            query SpineScoutUpcoming($limit: Int!, $from: date!) {
              books(
                where: {release_date: {_gt: $from}}
                order_by: {users_count: desc_nulls_last}
                limit: $limit
              ) { ' . self::BOOKS_FIELDS . ' }
            }
        ';
        return $this->mapBooks(
            $this->graphql($integration, $query, ['limit' => $limit, 'from' => $today]),
            $integration,
        );
    }

    /**
     * Proxy for "Staff Picks" — Hardcover has no first-party curated shelf, so we use
     * highly-rated books with enough ratings to be meaningful.
     *
     * @return list<TrendingBook>
     *
     * @throws HardcoverException
     */
    public function fetchStaffPicks(Integration $integration, int $limit = 25): array
    {
        $query = '
            query SpineScoutStaffPicks($limit: Int!) {
              books(
                where: {rating: {_gte: "4.3"}, ratings_count: {_gte: 500}}
                order_by: {rating: desc_nulls_last}
                limit: $limit
              ) { ' . self::BOOKS_FIELDS . ' }
            }
        ';
        return $this->mapBooks(
            $this->graphql($integration, $query, ['limit' => $limit]),
            $integration,
        );
    }

    /**
     * @return list<PopularAuthor>
     *
     * @throws HardcoverException
     */
    public function fetchPopularAuthors(Integration $integration, int $limit = 25): array
    {
        $query = <<<'GQL'
            query SpineScoutPopularAuthors($limit: Int!) {
              authors(
                where: {users_count: {_gt: 0}}
                order_by: {users_count: desc_nulls_last}
                limit: $limit
              ) {
                name
                slug
                cached_image
              }
            }
            GQL;
        $data = $this->graphql($integration, $query, ['limit' => $limit]);
        $rows = $data['authors'] ?? null;
        if (!is_array($rows)) {
            throw new HardcoverException('Unexpected popular authors payload: missing `authors`.');
        }
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['name'])) {
                continue;
            }
            $slug = !empty($row['slug']) ? (string) $row['slug'] : null;
            $out[] = new PopularAuthor(
                name: (string) $row['name'],
                slug: $slug,
                imageUrl: $this->extractCoverUrl($row['cached_image'] ?? null),
                externalUrl: $slug !== null ? 'https://hardcover.app/authors/' . $slug : null,
            );
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<TrendingBook>
     */
    private function mapBooks(array $data, Integration $integration): array
    {
        $rows = $data['books'] ?? null;
        if (!is_array($rows)) {
            throw new HardcoverException('Unexpected books payload: missing `books`.');
        }
        $prefs = $integration->getHardcoverEditionPreferences();
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['title'])) {
                continue;
            }
            $out[] = new TrendingBook(
                title: (string) $row['title'],
                author: $this->extractAuthor($row['cached_contributors'] ?? null),
                coverUrl: $this->extractCoverUrl($row['cached_image'] ?? null),
                externalUrl: !empty($row['slug']) ? 'https://hardcover.app/books/' . $row['slug'] : null,
                isbns: $this->extractIsbns($row['editions'] ?? null, $prefs),
            );
        }
        return $out;
    }

    /**
     * Edition for publisher/language/ISBN is picked per the integration's edition-sort preferences.
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
     * @throws HardcoverException
     */
    public function fetchBookMetadataBySlug(Integration $integration, string $slug): array
    {
        $query = <<<'GQL'
            query SpineScoutBookBySlug($slug: String!) {
              books(where: {slug: {_eq: $slug}}, limit: 1) {
                title
                slug
                release_date
                description
                cached_image
                cached_contributors
                cached_tags
                book_series {
                  position
                  series { name books_count }
                }
                editions(limit: 50, where: {_or: [{isbn_10: {_is_null: false}}, {isbn_13: {_is_null: false}}]}) {
                  isbn_10
                  isbn_13
                  release_date
                  physical_format
                  users_count
                  publisher { name }
                  language { code3 }
                  country { code2 }
                }
              }
            }
            GQL;
        $data = $this->graphql($integration, $query, ['slug' => $slug]);
        $rows = $data['books'] ?? null;
        if (!is_array($rows) || !isset($rows[0]) || !is_array($rows[0])) {
            throw new HardcoverException('Hardcover returned no book for slug "' . $slug . '".');
        }
        $row = $rows[0];

        $author = $this->extractAuthor($row['cached_contributors'] ?? null);
        $genres = [];
        $tags = $row['cached_tags'] ?? null;
        if (is_array($tags)) {
            // cached_tags is `{ Genre: [{tag: "Fantasy", ...}], Mood: [...], ... }`.
            foreach ($tags as $bucket => $entries) {
                if (!is_array($entries)) {
                    continue;
                }
                foreach ($entries as $entry) {
                    if (is_array($entry) && !empty($entry['tag'])) {
                        $genres[] = (string) $entry['tag'];
                    } elseif (is_string($entry) && $entry !== '') {
                        $genres[] = $entry;
                    }
                }
                // Only Genre/Tag-style buckets — drop "Content Warning" etc. when discernible.
                if (is_string($bucket) && stripos($bucket, 'warn') !== false) {
                    array_splice($genres, -count(is_array($entries) ? $entries : []));
                }
            }
        }
        $genres = array_values(array_unique($genres));

        // Series: Hardcover models a book→series many-to-many. Take the first
        // entry (the canonical series), since multi-series listings are rare.
        $series = null;
        $seriesIndex = null;
        $seriesTotal = null;
        $bs = $row['book_series'] ?? null;
        if (is_array($bs) && isset($bs[0]) && is_array($bs[0])) {
            $first = $bs[0];
            if (!empty($first['series']['name'])) {
                $series = (string) $first['series']['name'];
            }
            if (isset($first['position'])) {
                $seriesIndex = (string) $first['position'];
            }
            if (isset($first['series']['books_count']) && is_int($first['series']['books_count'])) {
                $seriesTotal = (int) $first['series']['books_count'];
            }
        }

        $edition = $this->pickPreferredEdition(
            is_array($row['editions'] ?? null) ? $row['editions'] : [],
            $integration->getHardcoverEditionPreferences(),
        );

        return [
            'title'         => !empty($row['title']) ? (string) $row['title'] : null,
            'author'        => $author,
            'publisher'     => $edition !== null && !empty($edition['publisher']['name']) ? (string) $edition['publisher']['name'] : null,
            'publishedDate' => !empty($row['release_date'])
                ? (string) $row['release_date']
                : ($edition !== null && !empty($edition['release_date']) ? (string) $edition['release_date'] : null),
            'language'      => $edition !== null && !empty($edition['language']['code3']) ? (string) $edition['language']['code3'] : null,
            'isbn'          => $edition !== null
                ? (\App\Repository\BookRepository::normalizeIsbn($edition['isbn_13'] ?? null)
                    ?? \App\Repository\BookRepository::normalizeIsbn($edition['isbn_10'] ?? null))
                : null,
            'description'   => !empty($row['description']) ? (string) $row['description'] : null,
            'genres'        => $genres,
            'series'        => $series,
            'seriesIndex'   => $seriesIndex,
            'seriesTotal'   => $seriesTotal,
        ];
    }

    /**
     * Includes top Book-type contributions so the popup can show a "selected works" list inline.
     *
     * @return array{
     *     name: ?string,
     *     bio: ?string,
     *     imageUrl: ?string,
     *     externalUrl: ?string,
     *     location: ?string,
     *     bornYear: ?int,
     *     deathYear: ?int,
     *     booksCount: ?int,
     *     usersCount: ?int,
     *     topBooks: list<array{title: string, slug: ?string, coverUrl: ?string}>,
     * }
     *
     * @throws HardcoverException
     */
    public function fetchAuthorMetadataBySlug(Integration $integration, string $slug): array
    {
        $query = <<<'GQL'
            query SpineScoutAuthorBySlug($slug: String!) {
              authors(where: {slug: {_eq: $slug}}, limit: 1) {
                name
                slug
                bio
                location
                born_year
                death_year
                books_count
                users_count
                cached_image
                contributions(
                  where: {contributable_type: {_eq: "Book"}, book: {users_count: {_gt: 0}}}
                  order_by: {book: {users_count: desc_nulls_last}}
                  limit: 25
                ) {
                  book {
                    title
                    slug
                    cached_image
                  }
                }
              }
            }
            GQL;
        $data = $this->graphql($integration, $query, ['slug' => $slug]);
        $rows = $data['authors'] ?? null;
        if (!is_array($rows) || !isset($rows[0]) || !is_array($rows[0])) {
            throw new HardcoverException('Hardcover returned no author for slug "' . $slug . '".');
        }
        $row = $rows[0];

        $topBooks = [];
        $contributions = $row['contributions'] ?? null;
        if (is_array($contributions)) {
            $seen = [];
            foreach ($contributions as $c) {
                if (!is_array($c) || !is_array($c['book'] ?? null)) {
                    continue;
                }
                $title = $c['book']['title'] ?? null;
                if (!is_string($title) || $title === '' || isset($seen[$title])) {
                    continue;
                }
                $seen[$title] = true;
                $bookSlug = $c['book']['slug'] ?? null;
                $topBooks[] = [
                    'title' => $title,
                    'slug' => is_string($bookSlug) && $bookSlug !== '' ? $bookSlug : null,
                    'coverUrl' => $this->extractCoverUrl($c['book']['cached_image'] ?? null),
                ];
            }
        }

        return [
            'name'        => !empty($row['name']) ? (string) $row['name'] : null,
            'bio'         => !empty($row['bio']) ? (string) $row['bio'] : null,
            'imageUrl'    => $this->extractCoverUrl($row['cached_image'] ?? null),
            'externalUrl' => !empty($row['slug']) ? 'https://hardcover.app/authors/' . $row['slug'] : null,
            'location'    => !empty($row['location']) ? (string) $row['location'] : null,
            'bornYear'    => isset($row['born_year']) && is_int($row['born_year']) ? $row['born_year'] : null,
            'deathYear'   => isset($row['death_year']) && is_int($row['death_year']) ? $row['death_year'] : null,
            'booksCount'  => isset($row['books_count']) && is_int($row['books_count']) ? $row['books_count'] : null,
            'usersCount'  => isset($row['users_count']) && is_int($row['users_count']) ? $row['users_count'] : null,
            'topBooks'    => $topBooks,
        ];
    }

    /**
     * Same priority order as extractIsbns: language, then format, then country, then popularity.
     *
     * @param list<array<string, mixed>> $editions
     * @param array{languages: list<string>, formats: list<string>, countries: list<string>} $prefs
     * @return array<string, mixed>|null
     */
    private function pickPreferredEdition(array $editions, array $prefs): ?array
    {
        if ($editions === []) {
            return null;
        }
        $langRank = self::rankMap($prefs['languages']);
        $fmtRank  = self::rankMap(array_map(static fn (string $v) => strtolower($v), $prefs['formats']));
        $cntRank  = self::rankMap($prefs['countries']);

        $best = null;
        $bestKey = null;
        foreach ($editions as $i => $e) {
            if (!is_array($e)) {
                continue;
            }
            $lang = isset($e['language']['code3']) ? strtolower((string) $e['language']['code3']) : '';
            $fmt  = isset($e['physical_format']) ? strtolower((string) $e['physical_format']) : '';
            $cnt  = isset($e['country']['code2']) ? strtoupper((string) $e['country']['code2']) : '';
            $key = [
                $langRank[$lang] ?? PHP_INT_MAX,
                $fmtRank[$fmt] ?? PHP_INT_MAX,
                $cntRank[$cnt] ?? PHP_INT_MAX,
                -(int) ($e['users_count'] ?? 0),
                $i,
            ];
            if ($bestKey === null || $key < $bestKey) {
                $bestKey = $key;
                $best = $e;
            }
        }
        return $best;
    }

    /** @throws HardcoverException */
    public function verifyToken(Integration $integration): string
    {
        $data = $this->graphql($integration, 'query { me { id username } }');
        // `me` is documented to return a list in Hasura; tolerate either shape.
        $me = $data['me'] ?? null;
        if (is_array($me) && isset($me[0]['username'])) {
            return (string) $me[0]['username'];
        }
        if (is_array($me) && isset($me['username'])) {
            return (string) $me['username'];
        }
        throw new HardcoverException('Token accepted but `me` returned no user.');
    }

    /**
     * @param array<string, mixed> $variables
     * @return array<string, mixed>
     *
     * @throws HardcoverException
     */
    private function graphql(Integration $integration, string $query, array $variables = []): array
    {
        $token = (string) ($integration->getCredentials()['token'] ?? '');
        if ($token === '') {
            throw new HardcoverException('Hardcover API token is not configured.');
        }

        try {
            $response = $this->httpClient->request('POST', self::ENDPOINT, [
                'timeout' => 30,
                'headers' => [
                    'Authorization' => str_starts_with($token, 'Bearer ') ? $token : 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'User-Agent' => self::USER_AGENT,
                ],
                'json' => ['query' => $query, 'variables' => $variables],
            ]);
            $status = $response->getStatusCode();
            if ($status === 401 || $status === 403) {
                throw new HardcoverException(sprintf('Hardcover rejected our API token (HTTP %d).', $status));
            }
            if ($status === 429) {
                throw new HardcoverException('Hardcover rate-limited the request (HTTP 429).');
            }
            if ($status >= 400) {
                throw new HardcoverException(sprintf('Hardcover returned HTTP %d.', $status));
            }
            $body = $response->toArray(false);
        } catch (TransportException $e) {
            throw new HardcoverException('Could not reach Hardcover: ' . $e->getMessage(), previous: $e);
        } catch (HttpExceptionInterface $e) {
            throw new HardcoverException('Could not parse Hardcover response: ' . $e->getMessage(), previous: $e);
        }

        if (isset($body['errors']) && is_array($body['errors']) && $body['errors'] !== []) {
            $first = $body['errors'][0]['message'] ?? 'unknown GraphQL error';
            throw new HardcoverException('Hardcover GraphQL error: ' . $first);
        }
        $data = $body['data'] ?? null;
        if (!is_array($data)) {
            throw new HardcoverException('Hardcover response missing `data` field.');
        }
        return $data;
    }

    private function extractAuthor(mixed $contributors): ?string
    {
        if (!is_array($contributors)) {
            return null;
        }
        $names = [];
        foreach ($contributors as $c) {
            if (is_array($c) && !empty($c['author']['name'])) {
                $names[] = (string) $c['author']['name'];
            } elseif (is_array($c) && !empty($c['name'])) {
                $names[] = (string) $c['name'];
            } elseif (is_string($c) && $c !== '') {
                $names[] = $c;
            }
        }
        return $names === [] ? null : implode(', ', array_slice($names, 0, 3));
    }

    /**
     * Sorted in PHP rather than GraphQL because Hasura `order_by` can't express
     * "prefer X first, otherwise anything".
     *
     * @param array{languages: list<string>, formats: list<string>, countries: list<string>} $prefs
     * @return list<string>
     */
    private function extractIsbns(mixed $editions, array $prefs): array
    {
        if (!is_array($editions)) {
            return [];
        }

        $langRank = self::rankMap($prefs['languages']);
        $fmtRank  = self::rankMap(array_map(static fn (string $v) => strtolower($v), $prefs['formats']));
        $cntRank  = self::rankMap($prefs['countries']);

        $rows = [];
        foreach ($editions as $i => $e) {
            if (!is_array($e)) {
                continue;
            }
            $rows[] = [
                'lang'    => isset($e['language']['code3']) ? strtolower((string) $e['language']['code3']) : null,
                'fmt'     => isset($e['physical_format']) ? strtolower((string) $e['physical_format']) : null,
                'country' => isset($e['country']['code2']) ? strtoupper((string) $e['country']['code2']) : null,
                'users'   => (int) ($e['users_count'] ?? 0),
                'idx'     => $i,
                'edition' => $e,
            ];
        }

        usort($rows, static function (array $a, array $b) use ($langRank, $fmtRank, $cntRank): int {
            // Lower rank = higher priority (0 = first preference, PHP_INT_MAX = no match).
            $ra = $langRank[$a['lang'] ?? ''] ?? PHP_INT_MAX;
            $rb = $langRank[$b['lang'] ?? ''] ?? PHP_INT_MAX;
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }
            $ra = $fmtRank[$a['fmt'] ?? ''] ?? PHP_INT_MAX;
            $rb = $fmtRank[$b['fmt'] ?? ''] ?? PHP_INT_MAX;
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }
            $ra = $cntRank[$a['country'] ?? ''] ?? PHP_INT_MAX;
            $rb = $cntRank[$b['country'] ?? ''] ?? PHP_INT_MAX;
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }
            // Tiebreaker: popular editions before obscure ones.
            if ($a['users'] !== $b['users']) {
                return $b['users'] <=> $a['users'];
            }
            // Final tiebreaker: original API order, for determinism.
            return $a['idx'] <=> $b['idx'];
        });

        $seen = [];
        foreach ($rows as $row) {
            $e = $row['edition'];
            foreach (['isbn_10', 'isbn_13'] as $field) {
                $isbn = \App\Repository\BookRepository::normalizeIsbn($e[$field] ?? null);
                if ($isbn !== null) {
                    $seen[$isbn] = true;
                }
            }
        }
        // array_keys coerces numeric-string keys to int; cast back so JSON
        // round-trips as list<string> rather than mixed int|string.
        return array_map(static fn ($k) => (string) $k, array_keys($seen));
    }

    /**
     * @param list<string> $preferences
     * @return array<string, int>
     */
    private static function rankMap(array $preferences): array
    {
        $out = [];
        foreach ($preferences as $i => $value) {
            $out[$value] ??= $i;
        }
        return $out;
    }

    private function extractCoverUrl(mixed $image): ?string
    {
        if (is_array($image) && !empty($image['url'])) {
            return (string) $image['url'];
        }
        if (is_string($image) && $image !== '') {
            return $image;
        }
        return null;
    }
}
