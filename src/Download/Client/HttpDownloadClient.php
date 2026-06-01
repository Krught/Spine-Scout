<?php

declare(strict_types=1);

namespace App\Download\Client;

use App\Download\Bypass\BypassResolver;
use App\Download\Bypass\ChallengePage;
use App\Download\Progress\DownloadProgressReporter;
use App\Download\Progress\NullDownloadProgressReporter;
use App\Search\Source\DirectHttpProtocol\AAStyleHttpProtocol;
use App\Search\Source\ReleaseCandidate;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Built-in HTTP download client: streams a URL straight to a file in the staging
 * directory. Synchronous — addDownload() blocks until the file is fully fetched
 * and getStatus() then reports it complete. Failures throw, so the caller can
 * fail over to the next candidate link; Messenger's retry covers transient
 * transport errors.
 *
 * AA "slow partner" links don't stream the file directly: GETting one returns a
 * small HTML interstitial that names a one-off URL on a partner host. When the
 * response is HTML we resolve that partner URL (AAStyleHttpProtocol) and fetch
 * it instead — one hop only. A browser reaches the slow-download page from the
 * record's /md5/{hash} page, and mirrors 403 the endpoint without that context,
 * so we reconstruct the matching Referer from the URL itself.
 *
 * The slow/fast-download pages are commonly behind an anti-bot challenge
 * (DDoS-Guard, Cloudflare) that a scripted GET can't clear — it returns the
 * challenge body, not the file. So when a bypass is enabled (the default), we go
 * bypass-first for those endpoints: resolve the page through BypassResolver
 * (headless Chromium or FlareSolverr), which clears the challenge and returns
 * the real interstitial HTML, parse the partner URL from it, and download the
 * file directly — the partner host itself is not challenge-gated. The file bytes
 * never go through the bypasser (it only returns HTML). With bypass off we still
 * try a plain fetch, and either way a challenge body is detected and rejected
 * rather than saved as a bogus "successful" download.
 *
 * Auto-registered via the app.download_client tag. Needs no credentials, so it
 * is always configured.
 */
final class HttpDownloadClient implements DownloadClientInterface
{
    private const TIMEOUT = 30;
    private const MAX_DURATION = 1800; // 30 min hard cap on a single fetch
    private const MAX_REDIRECTS = 5;
    private const MAX_RESOLVE_HOPS = 1; // interstitial -> partner file, no further
    private const SNIFF_BYTES = 4096;   // head kept to detect a challenge served as the "file"
    private const PROBE_PREVIEW_BYTES = 100; // head shown in the dev link test to eyeball what got fetched
    private const BYPASS_RESOLVE_ATTEMPTS = 2;     // re-resolve once if the link isn't ready
    private const BYPASS_RETRY_DELAY_SECONDS = 6;  // wait for the AA waitlist countdown to elapse
    private const HEADERS = [
        'User-Agent' => 'Mozilla/5.0 (compatible; SpineScout/1.0)',
        'Accept'     => '*/*',
    ];

    /** @var array<string, DownloadStatus> */
    private array $statuses = [];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $stagingDir,
        private readonly AAStyleHttpProtocol $protocol,
        private readonly BypassResolver $bypass,
    ) {
    }

    public function getName(): string
    {
        return 'http';
    }

    public function getProtocol(): string
    {
        return ReleaseCandidate::PROTOCOL_HTTP;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    /**
     * @return array{0: bool, 1: string}
     */
    public function testConnection(): array
    {
        if (!$this->ensureStagingDir()) {
            return [false, "Staging directory is not writable: {$this->stagingDir}"];
        }

        return [true, 'Built-in HTTP downloader ready.'];
    }

    /**
     * @param array<string, mixed> $options
     */
    public function addDownload(string $url, string $name, array $options = []): string
    {
        $progress = ($options['progress'] ?? null) instanceof DownloadProgressReporter
            ? $options['progress']
            : new NullDownloadProgressReporter();

        if (!$this->ensureStagingDir()) {
            throw new \RuntimeException("Staging directory is not writable: {$this->stagingDir}");
        }

        $id = bin2hex(random_bytes(8));
        $partPath = $this->stagingDir . '/' . $id . '.part';
        $finalPath = $this->stagingDir . '/' . $id;

        $bytes = $this->fetchToPath($url, $partPath, $progress);

        if (!@rename($partPath, $finalPath)) {
            @unlink($partPath);
            throw new \RuntimeException("Could not finalise staged file: {$finalPath}");
        }

        $progress->step(sprintf('File downloaded (%s)', $this->humanBytes($bytes)));

        $this->statuses[$id] = new DownloadStatus(
            state: DownloadStatus::STATE_COMPLETE,
            progress: 100.0,
            filePath: $finalPath,
        );

        return $id;
    }

    /**
     * Download $url to a throwaway temp file solely to inspect whether the link
     * yields a real file, returning its byte size AND a short printable preview
     * of the head bytes. Reuses the exact same fetch path as a real download
     * (bypass-first for challenged endpoints, one interstitial hop, anti-bot
     * challenge rejection) but stages to the system temp dir and DELETES the file
     * before returning — nothing is kept. Powers the dev direct-download link
     * test: the byte size alone can't tell a real file from a wait/landing page
     * served in its place (e.g. WeLib's ~90s countdown HTML), but the head
     * preview makes it obvious — a real EPUB/PDF starts "PK…"/"%PDF" while an
     * interstitial reads "<!DOCTYPE html>…". Throws on any failure, as addDownload().
     *
     * @param array<string, mixed> $options
     *
     * @return array{bytes: int, preview: string}
     */
    public function probeDownload(string $url, array $options = []): array
    {
        $progress = ($options['progress'] ?? null) instanceof DownloadProgressReporter
            ? $options['progress']
            : new NullDownloadProgressReporter();

        $tmpPath = @tempnam(sys_get_temp_dir(), 'sdl-dlprobe-');
        if ($tmpPath === false) {
            throw new \RuntimeException('Could not create a temp file for the download test.');
        }

        try {
            $bytes = $this->fetchToPath($url, $tmpPath, $progress);
            $preview = $this->previewHead($tmpPath);
        } finally {
            @unlink($tmpPath); // test only — never keep the downloaded file
        }

        $progress->step(sprintf('File downloaded (%s)', $this->humanBytes($bytes)));

        return ['bytes' => $bytes, 'preview' => $preview];
    }

    /**
     * Run the full download workflow for $url into the file at $path, returning
     * its byte size. This is the shared core of addDownload() (real fulfillment)
     * and probeDownloadSize() (the dev link test), so both execute byte-for-byte
     * the same fetch path — bypass-first resolution, the interstitial→partner
     * hop, anti-bot challenge rejection, streaming. Throws and removes a partial
     * file on any failure or an empty result.
     */
    private function fetchToPath(string $url, string $path, DownloadProgressReporter $progress): int
    {
        $handle = @fopen($path, 'wb');
        if ($handle === false) {
            throw new \RuntimeException("Could not open file for writing: {$path}");
        }

        try {
            $this->fetchTo($url, $handle, null, 0, $progress);
        } catch (\Throwable $e) {
            fclose($handle);
            @unlink($path);
            throw new \RuntimeException('Download failed: ' . $e->getMessage(), 0, $e);
        }

        fclose($handle);

        $bytes = filesize($path);
        if ($bytes === false || $bytes === 0) {
            @unlink($path);
            throw new \RuntimeException("Download produced an empty file from {$url}");
        }

        return $bytes;
    }

    /**
     * GET $url and write the file bytes to $handle. When the response is an AA
     * slow-download interstitial (HTML, not the file), resolve the partner URL
     * it names and recurse once to fetch the real file. $referer carries the
     * navigation context the next request needs; $hop bounds the indirection.
     *
     * @param resource $handle
     */
    private function fetchTo(string $url, $handle, ?string $referer, int $hop, DownloadProgressReporter $progress): void
    {
        // Bypass-first for the AA download endpoints: they're usually behind an
        // anti-bot challenge a plain GET can't clear, so resolve the page in the
        // bypass (headless Chromium / FlareSolverr), parse the real partner link
        // from the cleared HTML, and download that. Falls through to a plain
        // fetch when no bypass is enabled or it yields nothing usable.
        if ($hop === 0 && $this->isDownloadEndpoint($url) && $this->bypass->isEnabled()) {
            $partner = $this->resolveViaBypass($url, $progress);
            if ($partner !== null) {
                $progress->step(sprintf('Found the real download link (%s); fetching the file', $partner));
                $this->fetchTo($partner, $handle, $url, $hop + 1, $progress);

                return;
            }
            $progress->warn('Bypass did not yield a download link; trying a direct fetch');
        }

        $headers = self::HEADERS;
        $referer ??= $this->refererFor($url);
        if ($referer !== null) {
            $headers['Referer'] = $referer;
        }

        $response = $this->httpClient->request('GET', $url, [
            'timeout'       => self::TIMEOUT,
            'max_duration'  => self::MAX_DURATION,
            'max_redirects' => self::MAX_REDIRECTS,
            'headers'       => $headers,
        ]);

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            // A 403 here is the challenge signal. Bypass-first already had its
            // turn above (when enabled), so there's nothing more to try — fail
            // over to the next candidate link.
            throw new \RuntimeException("HTTP {$status} fetching {$url}");
        }

        if ($this->isHtml($response)) {
            if ($hop >= self::MAX_RESOLVE_HOPS) {
                throw new \RuntimeException("Unresolved interstitial fetching {$url}");
            }
            // A non-challenged mirror serves the interstitial directly; parse the
            // partner link from it. (When a bypass is on we resolved it above; a
            // challenge page reaching here means bypass was off or didn't clear,
            // so we don't re-invoke it.)
            $partner = $this->protocol->parsePartnerDownloadUrl($response->getContent(false), $url);
            if ($partner === null) {
                throw new \RuntimeException("No partner download link on interstitial page {$url}");
            }
            $progress->step(sprintf('Found the real download link (%s); fetching the file', $partner));
            $this->fetchTo($partner, $handle, $url, $hop + 1, $progress);

            return;
        }

        $progress->step('Downloading the file…');
        $this->streamToHandle($response, $handle, $url);
    }

    /**
     * Stream the response body to $handle, keeping the head so we can reject an
     * anti-bot challenge that a server returned with a non-HTML content type
     * (e.g. DDoS-Guard JS) — otherwise it would be saved as a bogus "file".
     *
     * @param resource $handle
     */
    private function streamToHandle(ResponseInterface $response, $handle, string $url): void
    {
        $head = '';
        foreach ($this->httpClient->stream($response) as $chunk) {
            $bytes = $chunk->getContent();
            if (\strlen($head) < self::SNIFF_BYTES) {
                $head .= substr($bytes, 0, self::SNIFF_BYTES - \strlen($head));
            }
            fwrite($handle, $bytes);
        }

        if (ChallengePage::looksLikeChallenge($head)) {
            throw new \RuntimeException("Anti-bot challenge returned instead of a file from {$url}");
        }
    }

    /**
     * The first PROBE_PREVIEW_BYTES of the fetched file, rendered as a printable
     * one-liner so it is safe to JSON-encode and eyeball regardless of whether
     * the bytes are a real file ("PK…" for an EPUB/ZIP, "%PDF" for a PDF) or an
     * HTML wait/landing page served in its place. Non-printable bytes collapse to
     * a space (whitespace) or "·" (everything else); empty file → empty string.
     */
    private function previewHead(string $path): string
    {
        $raw = @file_get_contents($path, false, null, 0, self::PROBE_PREVIEW_BYTES);
        if ($raw === false || $raw === '') {
            return '';
        }

        return (string) preg_replace_callback(
            '/[^\x20-\x7e]/',
            static fn (array $m): string => ctype_space($m[0]) ? ' ' : '·',
            $raw,
        );
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = $bytes / 1024;
        $i = 0;
        while ($value >= 1024 && $i < \count($units) - 1) {
            $value /= 1024;
            ++$i;
        }

        return sprintf('%.1f %s', $value, $units[$i]);
    }

    /**
     * Fetch $url through the operator's configured bypasser and parse the
     * partner-server file URL out of the resolved HTML. Null when bypass is off,
     * couldn't get past the challenge, or the page named no partner link.
     */
    private function resolveViaBypass(string $url, DownloadProgressReporter $progress): ?string
    {
        for ($attempt = 1; $attempt <= self::BYPASS_RESOLVE_ATTEMPTS; ++$attempt) {
            $html = $this->bypass->fetch($url, $progress);
            if ($html === null) {
                return null; // the bypass itself failed; retrying here won't help.
            }

            $partner = $this->protocol->parsePartnerDownloadUrl($html, $url);
            if ($partner !== null) {
                return $partner;
            }

            // The page cleared but named no partner link — usually the AA
            // waitlist countdown hasn't elapsed yet. Wait and re-resolve once
            // before giving up; the caller then fails over to the next link.
            if ($attempt < self::BYPASS_RESOLVE_ATTEMPTS) {
                $progress->step(sprintf('Download link not ready yet; waiting %ds and retrying…', self::BYPASS_RETRY_DELAY_SECONDS));
                sleep(self::BYPASS_RETRY_DELAY_SECONDS);
            }
        }

        $progress->warn('Loaded the page but found no download link on it');

        return null;
    }

    private function isDownloadEndpoint(string $url): bool
    {
        return $this->refererFor($url) !== null;
    }

    private function isHtml(ResponseInterface $response): bool
    {
        $contentType = $response->getHeaders(false)['content-type'][0] ?? '';

        return stripos($contentType, 'text/html') !== false;
    }

    /**
     * The /md5/{hash} page a browser would arrive from when following an AA
     * slow/fast-download link, reconstructed from the link itself. Returns null
     * for any other URL (no synthetic Referer worth sending).
     */
    private function refererFor(string $url): ?string
    {
        if (!preg_match('#^(https?://[^/]+)/(?:slow|fast)_download/([0-9a-f]+)#i', $url, $m)) {
            return null;
        }

        return $m[1] . '/md5/' . $m[2];
    }

    public function getStatus(string $downloadId): DownloadStatus
    {
        return $this->statuses[$downloadId]
            ?? new DownloadStatus(state: DownloadStatus::STATE_UNKNOWN, progress: 0.0, message: 'Unknown download id');
    }

    public function cancel(string $downloadId, bool $deleteFiles = false): bool
    {
        $status = $this->statuses[$downloadId] ?? null;
        if ($status !== null && $deleteFiles && $status->filePath !== null && is_file($status->filePath)) {
            @unlink($status->filePath);
        }
        unset($this->statuses[$downloadId]);

        return true;
    }

    private function ensureStagingDir(): bool
    {
        if (!is_dir($this->stagingDir) && !@mkdir($this->stagingDir, 0o775, true) && !is_dir($this->stagingDir)) {
            return false;
        }

        return is_writable($this->stagingDir);
    }
}
