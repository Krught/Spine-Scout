<?php

declare(strict_types=1);

namespace App\Tests\Download\Bypass;

use App\Download\Bypass\FlareSolverrBypasser;
use App\Download\Progress\NullDownloadProgressReporter;
use App\Search\DirectDownload\DirectDownloadConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class FlareSolverrBypasserTest extends TestCase
{
    public function testNotConfiguredWithoutUrl(): void
    {
        $bypasser = new FlareSolverrBypasser(new MockHttpClient(), new NullLogger());
        self::assertFalse($bypasser->isConfigured($this->config('')));
        self::assertSame(DirectDownloadConfig::BYPASS_EXTERNAL, $bypasser->mode());
    }

    public function testReturnsSolvedHtmlAndPostsToV1Endpoint(): void
    {
        $seen = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = ['method' => $method, 'url' => $url, 'body' => $options['body'] ?? ''];

            return new MockResponse(
                json_encode(['status' => 'ok', 'solution' => ['response' => '<html>solved</html>']]),
                ['response_headers' => ['content-type' => 'application/json']],
            );
        });
        $bypasser = new FlareSolverrBypasser($http, new NullLogger());

        // Bare host:port is normalised to an http:// base + /v1 endpoint.
        $html = $bypasser->fetch('https://annas-archive.gl/slow_download/abc/0/1', $this->config('10.0.0.5:8191'), new NullDownloadProgressReporter());

        self::assertSame('<html>solved</html>', $html);
        self::assertSame('POST', $seen['method']);
        self::assertSame('http://10.0.0.5:8191/v1', $seen['url']);
        self::assertStringContainsString('request.get', (string) $seen['body']);
        self::assertStringContainsString('slow_download', (string) $seen['body']);
    }

    public function testReturnsNullWhenSolverReportsFailure(): void
    {
        $http = new MockHttpClient(new MockResponse(
            json_encode(['status' => 'error', 'message' => 'challenge not solved']),
            ['response_headers' => ['content-type' => 'application/json']],
        ));
        $bypasser = new FlareSolverrBypasser($http, new NullLogger());

        self::assertNull($bypasser->fetch('https://m.test/slow_download/x/0/1', $this->config('http://fs:8191'), new NullDownloadProgressReporter()));
    }

    public function testReturnsNullOnTransportError(): void
    {
        $http = new MockHttpClient(new MockResponse('', ['http_code' => 500]));
        $bypasser = new FlareSolverrBypasser($http, new NullLogger());

        self::assertNull($bypasser->fetch('https://m.test/slow_download/x/0/1', $this->config('http://fs:8191'), new NullDownloadProgressReporter()));
    }

    private function config(string $url): DirectDownloadConfig
    {
        return new DirectDownloadConfig([], [], bypassMode: DirectDownloadConfig::BYPASS_EXTERNAL, bypassFlaresolverrUrl: $url);
    }
}
