<?php

declare(strict_types=1);

namespace App\Tests\Download\Bypass;

use App\Download\Bypass\ChallengePage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChallengePageTest extends TestCase
{
    #[DataProvider('challengeProvider')]
    public function testLooksLikeChallenge(string $body, bool $expected): void
    {
        self::assertSame($expected, ChallengePage::looksLikeChallenge($body));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function challengeProvider(): iterable
    {
        yield 'ddos-guard js beacon' => ["(function(){new Image().src='https://check.ddos-guard.net/set/id/abc';})()", true];
        yield 'cloudflare just a moment' => ['<title>Just a moment...</title>', true];
        yield 'cf challenge script' => ['<script>window._cf_chl_opt={}</script>', true];
        yield 'real pdf head' => ["%PDF-1.7\n%\xe2\xe3\xcf\xd3 binary...", false];
        yield 'real epub zip head' => ["PK\x03\x04 mimetypeapplication/epub+zip", false];
        yield 'empty' => ['', false];
    }
}
