<?php

declare(strict_types=1);

namespace App\Tests\Download\Client;

use App\Download\Bypass\BypasserInterface;
use App\Download\Bypass\BypassResolver;
use App\Download\Client\HttpDownloadClient;
use App\Download\Progress\DownloadProgressReporter;
use App\Search\BestMatch\BestMatchPolicy;
use App\Search\DirectDownload\DirectDownloadConfig;
use App\Search\SearchSettingsProvider;
use App\Search\Source\DirectHttpProtocol\AAStyleHttpProtocol;
use App\Search\Source\ReleaseCandidate;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HttpDownloadClientTest extends TestCase
{
    private string $staging;

    protected function setUp(): void
    {
        $this->staging = sys_get_temp_dir() . '/spinescout_dl_' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->staging)) {
            foreach (scandir($this->staging) ?: [] as $f) {
                if ($f !== '.' && $f !== '..') {
                    @unlink($this->staging . '/' . $f);
                }
            }
            @rmdir($this->staging);
        }
    }

    public function testProtocolAndConfigured(): void
    {
        $client = new HttpDownloadClient(new MockHttpClient(), $this->staging, new AAStyleHttpProtocol(), $this->resolver());
        self::assertSame('http', $client->getName());
        self::assertSame(ReleaseCandidate::PROTOCOL_HTTP, $client->getProtocol());
        self::assertTrue($client->isConfigured());
    }

    public function testStreamsBodyToStagingAndReportsComplete(): void
    {
        $client = new HttpDownloadClient(new MockHttpClient(new MockResponse('EPUBBYTES')), $this->staging, new AAStyleHttpProtocol(), $this->resolver());

        $id = $client->addDownload('https://mirror.test/slow_download/x/0/0', 'Red Rising');
        $status = $client->getStatus($id);

        self::assertTrue($status->isComplete());
        self::assertNotNull($status->filePath);
        self::assertFileExists($status->filePath);
        self::assertSame('EPUBBYTES', file_get_contents($status->filePath));
        self::assertSame(100.0, $status->progress);
    }

    public function testFollowsSlowDownloadInterstitialToPartnerFile(): void
    {
        $interstitial = '<html><body><a href="http://45.3.63.28:6060/d/book.epub">📚 Download now</a></body></html>';
        $requests = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests, $interstitial): MockResponse {
            $requests[] = ['url' => $url, 'headers' => $options['normalized_headers'] ?? []];
            if (str_contains($url, '/slow_download/')) {
                return new MockResponse($interstitial, ['response_headers' => ['content-type' => 'text/html; charset=utf-8']]);
            }

            return new MockResponse('REALEPUBBYTES', ['response_headers' => ['content-type' => 'application/epub+zip']]);
        });
        $client = new HttpDownloadClient($http, $this->staging, new AAStyleHttpProtocol(), $this->resolver());

        $id = $client->addDownload('https://annas-archive.gl/slow_download/7e5bdba3/0/7', 'Maus');
        $status = $client->getStatus($id);

        self::assertTrue($status->isComplete());
        self::assertSame('REALEPUBBYTES', file_get_contents((string) $status->filePath));
        // Two hops: the interstitial, then the partner file.
        self::assertCount(2, $requests);
        self::assertStringContainsString('/slow_download/', $requests[0]['url']);
        self::assertSame('http://45.3.63.28:6060/d/book.epub', $requests[1]['url']);
        // The slow-download request carries the reconstructed /md5 Referer.
        self::assertSame('Referer: https://annas-archive.gl/md5/7e5bdba3', $requests[0]['headers']['referer'][0] ?? null);
    }

    public function testBypassResolvesPartnerLinkAfter403(): void
    {
        // The slow-download endpoint Cloudflare-403s a scripted client; the
        // configured bypasser returns the resolved interstitial HTML, from which
        // we parse the partner link and download the file directly.
        $http = new MockHttpClient(function (string $method, string $url): MockResponse {
            if (str_contains($url, '/slow_download/')) {
                return new MockResponse('blocked', ['http_code' => 403]);
            }

            return new MockResponse('PARTNERBYTES', ['response_headers' => ['content-type' => 'application/pdf']]);
        });
        $bypasser = $this->bypasser(DirectDownloadConfig::BYPASS_EXTERNAL, '<a href="http://1.2.3.4:6060/d/book.pdf">Download now</a>');
        $client = new HttpDownloadClient($http, $this->staging, new AAStyleHttpProtocol(), $this->resolver($bypasser, DirectDownloadConfig::BYPASS_EXTERNAL));

        $id = $client->addDownload('https://annas-archive.gl/slow_download/abc/0/3', 'Maus');

        self::assertTrue($client->getStatus($id)->isComplete());
        self::assertSame('PARTNERBYTES', file_get_contents((string) $client->getStatus($id)->filePath));
    }

    public function testBypassFirstAvoidsChallengeAndDownloadsPartnerFile(): void
    {
        // With a bypass enabled, the challenged slow_download endpoint must NOT
        // be fetched directly — the bypasser resolves it, we parse the partner
        // link, and only the partner file is fetched over plain HTTP.
        $requests = [];
        $http = new MockHttpClient(function (string $method, string $url) use (&$requests): MockResponse {
            $requests[] = $url;
            if (str_contains($url, '/slow_download/')) {
                return new MockResponse('<html><body><script>ddos-guard</script></body></html>', ['response_headers' => ['content-type' => 'text/html']]);
            }

            return new MockResponse('REALFILE', ['response_headers' => ['content-type' => 'application/pdf']]);
        });
        $bypasser = $this->bypasser(DirectDownloadConfig::BYPASS_EXTERNAL, '<a href="http://9.9.9.9:6060/d/x.pdf">download</a>');
        $client = new HttpDownloadClient($http, $this->staging, new AAStyleHttpProtocol(), $this->resolver($bypasser, DirectDownloadConfig::BYPASS_EXTERNAL));

        $id = $client->addDownload('https://annas-archive.gl/slow_download/7e5bdba3/0/1', 'Maus');

        self::assertSame('REALFILE', file_get_contents((string) $client->getStatus($id)->filePath));
        self::assertSame(['http://9.9.9.9:6060/d/x.pdf'], $requests);
    }

    public function testEmitsProgressTrailOnBypassDownload(): void
    {
        $http = new MockHttpClient(fn (string $method, string $url): MockResponse => new MockResponse('REALFILE', ['response_headers' => ['content-type' => 'application/pdf']]));
        $bypasser = $this->bypasser(DirectDownloadConfig::BYPASS_EXTERNAL, '<a href="http://9.9.9.9:6060/d/x.pdf">download</a>');
        $client = new HttpDownloadClient($http, $this->staging, new AAStyleHttpProtocol(), $this->resolver($bypasser, DirectDownloadConfig::BYPASS_EXTERNAL));
        $progress = $this->captureProgress();

        $client->addDownload('https://annas-archive.gl/slow_download/7e5bdba3/0/1', 'Maus', ['progress' => $progress]);

        self::assertContains('Found the real download link (http://9.9.9.9:6060/d/x.pdf); fetching the file', $progress->steps);
        self::assertContains('Downloading the file…', $progress->steps);
        self::assertNotEmpty(array_filter($progress->steps, static fn (string $s): bool => str_starts_with($s, 'File downloaded')));
    }

    public function testReportsWhereBypassFailed(): void
    {
        // Bypass clears nothing (returns null), then the direct fetch 403s: the
        // activity trail must show the bypass step gave up before the failure.
        $http = new MockHttpClient(new MockResponse('blocked', ['http_code' => 403]));
        $bypasser = $this->bypasser(DirectDownloadConfig::BYPASS_EXTERNAL, null);
        $client = new HttpDownloadClient($http, $this->staging, new AAStyleHttpProtocol(), $this->resolver($bypasser, DirectDownloadConfig::BYPASS_EXTERNAL));
        $progress = $this->captureProgress();

        try {
            $client->addDownload('https://annas-archive.gl/slow_download/7e5bdba3/0/1', 'X', ['progress' => $progress]);
            self::fail('expected failure');
        } catch (\RuntimeException) {
            // expected
        }

        self::assertContains('Bypass did not yield a download link; trying a direct fetch', $progress->warns);
    }

    public function testChallengeBodyIsRejectedNotSavedAsFile(): void
    {
        // The exact failure the user hit: a DDoS-Guard JS challenge served with a
        // non-HTML content type. With bypass off it must FAIL, not "succeed".
        $challenge = "(function(){new Image().src='https://check.ddos-guard.net/set/id/24wof5lpqNFWFi75';})()";
        $http = new MockHttpClient(new MockResponse($challenge, ['response_headers' => ['content-type' => 'text/plain']]));
        $client = new HttpDownloadClient($http, $this->staging, new AAStyleHttpProtocol(), $this->resolver());

        $this->expectException(\RuntimeException::class);
        $client->addDownload('https://annas-archive.gl/slow_download/abc/0/1', 'X');
    }

    public function test403WithBypassDisabledStillThrows(): void
    {
        $client = new HttpDownloadClient(
            new MockHttpClient(new MockResponse('blocked', ['http_code' => 403])),
            $this->staging,
            new AAStyleHttpProtocol(),
            $this->resolver(), // bypass mode none
        );

        $this->expectException(\RuntimeException::class);
        $client->addDownload('https://annas-archive.gl/slow_download/abc/0/3', 'X');
    }

    public function testInterstitialWithoutPartnerLinkThrows(): void
    {
        $http = new MockHttpClient(new MockResponse(
            '<html><body>Please wait… checking your browser.</body></html>',
            ['response_headers' => ['content-type' => 'text/html']],
        ));
        $client = new HttpDownloadClient($http, $this->staging, new AAStyleHttpProtocol(), $this->resolver());

        $this->expectException(\RuntimeException::class);
        $client->addDownload('https://annas-archive.gl/slow_download/abc/0/1', 'X');
    }

    public function testHttpErrorThrows(): void
    {
        $client = new HttpDownloadClient(new MockHttpClient(new MockResponse('nope', ['http_code' => 404])), $this->staging, new AAStyleHttpProtocol(), $this->resolver());

        $this->expectException(\RuntimeException::class);
        $client->addDownload('https://mirror.test/missing', 'X');
    }

    public function testEmptyBodyThrows(): void
    {
        $client = new HttpDownloadClient(new MockHttpClient(new MockResponse('')), $this->staging, new AAStyleHttpProtocol(), $this->resolver());

        $this->expectException(\RuntimeException::class);
        $client->addDownload('https://mirror.test/empty', 'X');
    }

    public function testUnknownIdReportsUnknown(): void
    {
        $client = new HttpDownloadClient(new MockHttpClient(), $this->staging, new AAStyleHttpProtocol(), $this->resolver());
        self::assertSame('unknown', $client->getStatus('nope')->state);
    }

    // --- helpers ----------------------------------------------------------

    /** A BypassResolver wired with an optional fake bypasser and a fixed mode. */
    private function resolver(?BypasserInterface $bypasser = null, string $mode = DirectDownloadConfig::BYPASS_NONE): BypassResolver
    {
        $config = new DirectDownloadConfig([], [], bypassMode: $mode);
        $settings = new class($config) implements SearchSettingsProvider {
            public function __construct(private DirectDownloadConfig $config)
            {
            }

            public function getDirectDownloadConfig(): DirectDownloadConfig
            {
                return $this->config;
            }

            public function getBestMatchPolicy(): BestMatchPolicy
            {
                return BestMatchPolicy::default();
            }
        };

        return new BypassResolver($bypasser !== null ? [$bypasser] : [], $settings, new NullLogger());
    }

    /** A fake bypasser of the given mode that returns $html (null = couldn't resolve). */
    private function bypasser(string $mode, ?string $html): BypasserInterface
    {
        return new class($mode, $html) implements BypasserInterface {
            public function __construct(private string $modeValue, private ?string $html)
            {
            }

            public function mode(): string
            {
                return $this->modeValue;
            }

            public function isConfigured(DirectDownloadConfig $config): bool
            {
                return true;
            }

            public function fetch(string $url, DirectDownloadConfig $config, DownloadProgressReporter $progress): ?string
            {
                return $this->html;
            }
        };
    }

    /** A reporter that records every step/warn for assertions. */
    private function captureProgress(): DownloadProgressReporter
    {
        return new class implements DownloadProgressReporter {
            /** @var list<string> */
            public array $steps = [];
            /** @var list<string> */
            public array $warns = [];

            public function step(string $message): void
            {
                $this->steps[] = $message;
            }

            public function warn(string $message): void
            {
                $this->warns[] = $message;
            }
        };
    }
}
