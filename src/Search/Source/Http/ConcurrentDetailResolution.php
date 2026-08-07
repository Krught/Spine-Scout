<?php

declare(strict_types=1);

namespace App\Search\Source\Http;

use App\Search\DirectDownload\DirectDownloadConfig;
use App\Search\Source\ReleaseCandidate;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Detail resolution for the HTTP release sources, split into two pure-ish hooks so
 * the same code can serve one candidate or a whole page of them:
 *
 *   detailPlan()        — which URL (if any) this candidate's detail lives at, or
 *                         the finished degraded result when there is nothing to fetch
 *   detailFromResponse() — turn one fetched (or failed) response into the
 *                         resolveDetail() return shape
 *
 * resolveDetail() runs that pair for a single candidate — unchanged behaviour, and
 * still the path the dev probe and any other single-record caller take.
 *
 * resolveDetails() (BatchDetailResolverInterface) runs it for many: it ISSUES every
 * request first and only then consumes them, pumping the responses through
 * HttpClientInterface::stream() so the transport works on all of them at once
 * rather than blocking on each in request order. A per-candidate transport error,
 * HTTP error status or parse failure degrades exactly as the single path does
 * (populated `error`, no links) and never aborts the batch.
 *
 * Sources whose detail pages must go through an anti-bot bypass (FlareSolverr) can
 * override fetchDetailPage() for the single path and report
 * supportsConcurrentDetail() === false, which makes the batch fall back to a serial
 * loop over resolveDetail() — the bypass proxies one navigation at a time, so firing
 * a page of them concurrently would trip its own rate limits rather than help.
 *
 * Requires the using class to expose $httpClient and a request() with the
 * AbstractDirectHttpSource signature, plus the TIMEOUT/MAX_REDIRECTS/HEADERS consts.
 */
trait ConcurrentDetailResolution
{
    /**
     * Where this candidate's detail page lives. Return ['url' => $url, 'result' => null]
     * to fetch, or ['url' => null, 'result' => <resolveDetail shape>] when it cannot
     * be fetched at all (e.g. no mirror on the candidate).
     *
     * @return array{url: string|null, result: array{isbns: list<string>, raw: array<string, list<string>>, links: list<string>, error: string|null}|null}
     */
    abstract protected function detailPlan(ReleaseCandidate $candidate, ?DirectDownloadConfig $config): array;

    /**
     * @param array{html: string, status: int, error: string|null} $response
     *
     * @return array{isbns: list<string>, raw: array<string, list<string>>, links: list<string>, error: string|null}
     */
    abstract protected function detailFromResponse(ReleaseCandidate $candidate, array $response, ?DirectDownloadConfig $config): array;

    /**
     * @return array{isbns: list<string>, raw: array<string, list<string>>, links: list<string>, error: string|null}
     */
    public function resolveDetail(ReleaseCandidate $candidate, ?DirectDownloadConfig $config = null): array
    {
        $plan = $this->detailPlan($candidate, $config);
        if ($plan['result'] !== null) {
            return $plan['result'];
        }

        return $this->detailFromResponse($candidate, $this->fetchDetailPage((string) $plan['url']), $config);
    }

    /**
     * @param array<int, ReleaseCandidate> $candidates
     *
     * @return array<int, array{isbns: list<string>, raw: array<string, list<string>>, links: list<string>, error: string|null}>
     */
    public function resolveDetails(array $candidates, ?DirectDownloadConfig $config = null): array
    {
        if (\count($candidates) < 2 || !$this->supportsConcurrentDetail()) {
            $out = [];
            foreach ($candidates as $key => $candidate) {
                $out[$key] = $this->resolveDetail($candidate, $config);
            }

            return $out;
        }

        $out = [];
        /** @var array<int, ResponseInterface> $responses */
        $responses = [];
        $options = $this->httpRequestOptions();

        // 1. Issue everything up front — Symfony's client starts them all and only
        //    blocks when a body is consumed.
        foreach ($candidates as $key => $candidate) {
            $plan = $this->detailPlan($candidate, $config);
            if ($plan['result'] !== null) {
                $out[$key] = $plan['result'];
                continue;
            }
            try {
                $responses[$key] = $this->httpClient->request('GET', (string) $plan['url'], $options);
            } catch (\Throwable $e) {
                $out[$key] = $this->detailFromResponse($candidate, ['html' => '', 'status' => 0, 'error' => $e->getMessage()], $config);
            }
        }

        // 2. Pump them all to completion together. Errors surface when a chunk is
        //    touched; swallow them here and let step 3 report them per candidate,
        //    exactly as the single-candidate path does.
        if ($responses !== []) {
            try {
                foreach ($this->httpClient->stream($responses) as $chunk) {
                    try {
                        $chunk->isLast();
                    } catch (\Throwable) {
                        // Recorded when this response's content is read below.
                    }
                }
            } catch (\Throwable) {
                // A stream-level failure still leaves each response readable (and
                // failing) on its own below.
            }
        }

        // 3. Read + parse. Nothing blocks here: the bodies are already buffered.
        foreach ($responses as $key => $response) {
            $out[$key] = $this->detailFromResponse($candidates[$key], $this->responseResult($response), $config);
        }

        // Restore the caller's key order (planned-only results were filled first).
        $ordered = [];
        foreach ($candidates as $key => $_) {
            if (isset($out[$key])) {
                $ordered[$key] = $out[$key];
            }
        }

        return $ordered;
    }

    /**
     * False when this source's detail pages must be fetched one at a time (bypass
     * mode); resolveDetails() then degrades to a serial loop.
     */
    protected function supportsConcurrentDetail(): bool
    {
        return true;
    }

    /**
     * Fetch one detail page for the single-candidate path. Overridable so a source
     * can route it through an anti-bot bypass.
     *
     * @return array{html: string, status: int, error: string|null}
     */
    protected function fetchDetailPage(string $url): array
    {
        return $this->request($url);
    }

    /**
     * @return array{timeout: int, max_redirects: int, headers: array<string, string>}
     */
    protected function httpRequestOptions(): array
    {
        return [
            'timeout'       => static::TIMEOUT,
            'max_redirects' => static::MAX_REDIRECTS,
            'headers'       => static::HEADERS,
        ];
    }

    /**
     * Same contract as request(): never throws, failures come back as a structured
     * error. 4xx/5xx are NOT errors here (getContent(false)) — the parsers decide.
     *
     * @return array{html: string, status: int, error: string|null}
     */
    private function responseResult(ResponseInterface $response): array
    {
        try {
            return ['html' => $response->getContent(false), 'status' => $response->getStatusCode(), 'error' => null];
        } catch (\Throwable $e) {
            return ['html' => '', 'status' => 0, 'error' => $e->getMessage()];
        }
    }
}
