<?php

declare(strict_types=1);

namespace App\Search\DirectDownload;

use App\Entity\Book;
use App\Repository\IntegrationRepository;
use App\Search\Source\DirectHttpProtocol\AAStyleHttpProtocol;
use App\Search\Source\ReleaseSearchPlan;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Manual-probe helper for the direct-download search path: build the search URL
 * for a given ISBN / author / title from the operator's CURRENT saved settings,
 * and optionally fetch + parse the raw response.
 *
 * This is the shared implementation behind both the `spinescout:dd:probe`
 * console command and the Settings → Development page. It does, by hand, what
 * the not-yet-built DirectHttpSource engine will do automatically on approval:
 * read DirectDownloadConfig -> pick the search mirror -> build URL -> (fetch).
 */
final class DirectDownloadProbe
{
    public function __construct(
        private readonly IntegrationRepository $integrations,
        private readonly AAStyleHttpProtocol $protocol,
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    public function config(): DirectDownloadConfig
    {
        return $this->integrations->getDirectDownloadConfig();
    }

    public function buildPlan(
        ?string $isbn,
        ?string $author,
        ?string $title,
        ?string $publisher = null,
        ?string $publishedDate = null,
        ?string $language = null,
    ): ReleaseSearchPlan {
        $isbn = trim((string) $isbn);
        $author = trim((string) $author);
        $title = trim((string) $title);

        $book = new Book('probe', $isbn !== '' ? $isbn : 'manual', $title);
        if ($isbn !== '') {
            $book->setIsbns([$isbn]);
        }
        if (trim((string) $author) !== '') {
            $book->setAuthor($author);
        }
        if (trim((string) $publisher) !== '') {
            $book->setPublisher(trim((string) $publisher));
        }
        if (trim((string) $publishedDate) !== '') {
            $book->setPublishedDate(trim((string) $publishedDate));
        }
        if (trim((string) $language) !== '') {
            $book->setLanguage(trim((string) $language));
        }

        return new ReleaseSearchPlan(
            book: $book,
            isbnCandidates: $isbn !== '' ? [$isbn] : [],
            author: $author,
            titleVariants: $title !== '' ? [$title] : [],
        );
    }

    /**
     * Resolve the search mirror (top-priority enabled Anna's Archive mirror —
     * the only search-capable source) and build the query URL.
     *
     * @return array{mirror: string|null, url: string|null}
     */
    public function searchUrl(ReleaseSearchPlan $plan): array
    {
        $config = $this->config();
        $aa = DirectDownloadSource::AnnasArchive->value;
        $mirrors = $config->mirrorsFor($aa)->toArray();

        if ($mirrors === [] || !$config->isIndexerEnabled($aa)) {
            return ['mirror' => null, 'url' => null];
        }

        $base = $mirrors[0];

        return ['mirror' => $base, 'url' => $this->protocol->buildSearchUrl($base, $plan)];
    }

    /**
     * GET a search URL and parse it. Never throws — transport/parse failures are
     * returned as a structured result so callers can render them.
     *
     * @return array{status: int, bytes: int, records: list<\App\Search\Source\DirectHttpProtocol\AAStyleResult>, html: string, error: string|null}
     */
    public function fetch(string $url): array
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => 30,
                'max_redirects' => 5,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (compatible; SpineScout/1.0)',
                    'Accept' => 'text/html',
                ],
            ]);
            $status = $response->getStatusCode();
            $html = $response->getContent(false);
        } catch (\Throwable $e) {
            return ['status' => 0, 'bytes' => 0, 'records' => [], 'html' => '', 'error' => $e->getMessage()];
        }

        return [
            'status'  => $status,
            'bytes'   => strlen($html),
            'records' => $this->protocol->parseSearchResults($html),
            'html'    => $html,
            'error'   => null,
        ];
    }
}
