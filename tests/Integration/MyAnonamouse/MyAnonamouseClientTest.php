<?php

declare(strict_types=1);

namespace App\Tests\Integration\MyAnonamouse;

use App\Entity\Book;
use App\Integration\MyAnonamouse\MamRelease;
use App\Integration\MyAnonamouse\MyAnonamouseClient;
use App\Integration\MyAnonamouse\MyAnonamouseConfig;
use App\Search\Source\ReleaseCandidate;
use App\Search\Source\ReleaseSearchPlan;
use App\Search\Torrent\ProwlarrConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Covers the MAM transport end to end against recorded responses: freeleech paging
 * and its stop condition, the defensive field mapping (JSON-encoded author maps,
 * human size strings, freeleech flags, thumbnail key variants), session-cookie
 * rotation persistence, and the auth-failure degradation rules — an HTML login page
 * or a 403 must yield []/ok=false, never an exception and never a retry.
 */
final class MyAnonamouseClientTest extends TestCase
{
    public function testFetchFreeleechPagesUntilTheFoundTotalIsCovered(): void
    {
        $requests = [];
        $http = $this->mockClient([
            $this->json($this->fixture('search_page1.json')),
            $this->json($this->fixture('search_page2.json')),
        ], $requests);

        $releases = (new MyAnonamouseClient($http, new FakeMyAnonamouseSettings($this->config()), new NullLogger()))
            ->fetchFreeleech(13);

        self::assertCount(2, $requests, 'found=150 over perpage=100 means exactly two pages');
        self::assertCount(3, $releases);

        self::assertSame('POST', $requests[0]['method']);
        self::assertStringEndsWith('/tor/js/loadSearchJSONbasic.php', $requests[0]['url']);

        $body = urldecode($requests[0]['body']);
        self::assertStringContainsString('tor[searchType]=fl', $body);
        self::assertStringContainsString('tor[main_cat][0]=13', $body);
        self::assertStringContainsString('tor[srchIn][title]=true', $body);
        self::assertStringContainsString('tor[srchIn][author]=true', $body);
        self::assertStringContainsString('tor[srchIn][narrator]=true', $body);
        self::assertStringContainsString('tor[perpage]=100', $body);
        self::assertStringContainsString('tor[startNumber]=0', $body);
        self::assertStringContainsString('thumbnails=1', $body);
        self::assertStringContainsString('dlLink=1', $body, 'without the dlLink opt-in MAM omits every row\'s dl download hash');

        self::assertStringContainsString('tor[startNumber]=100', urldecode($requests[1]['body']), 'second page must advance the offset');
        self::assertStringContainsString('mam_id=session-cookie-value', implode("\n", $requests[0]['headers']));
    }

    public function testTheSweepWalksBackwardsInTimeWindowByWindowAndDedupesTheOverlap(): void
    {
        $requests = [];
        $http = $this->mockClient([
            // Window 1: MAM's ~200-row ceiling, newest first, with a far larger `found`;
            // the third page is where the server simply stops serving.
            $this->searchPage(range(1, 100), 0),
            $this->searchPage(range(101, 200), 100),
            $this->searchPage([], 200),
            // Window 2: pinned to window 1's oldest day, so the boundary day repeats.
            $this->searchPage(range(151, 250), 0),
            $this->searchPage([], 100),
            // Window 3: nothing but rows already in hand.
            $this->searchPage(range(201, 250), 0),
            $this->searchPage([], 100),
        ], $requests);

        $releases = (new MyAnonamouseClient($http, new FakeMyAnonamouseSettings($this->config()), new NullLogger(), 0))
            ->fetchFreeleech(13);

        self::assertCount(7, $requests, 'window 1 runs to the servers 200-row ceiling, then two more windows of one page each');
        self::assertCount(250, $releases, 'the overlap is deduped by torrent id');
        self::assertSame(range(1, 250), array_map(static fn ($release) => $release->mamTorrentId, $releases));

        foreach ($requests as $request) {
            self::assertStringContainsString('tor[sortType]=dateDesc', urldecode($request['body']), 'the walk depends on a newest-first order');
        }
        self::assertStringNotContainsString('tor[endDate]', urldecode($requests[0]['body']), 'the first window is unbounded');
        self::assertStringContainsString('tor[endDate]=' . $this->dateFor(200), urldecode($requests[3]['body']), 'window 2 ends on window 1s oldest day');
        self::assertStringContainsString('tor[startNumber]=0', urldecode($requests[3]['body']), 'each window pages from zero again');
        self::assertStringContainsString('tor[endDate]=' . $this->dateFor(250), urldecode($requests[5]['body']));
    }

    public function testASweepThatExhaustsMamsOwnTotalIssuesNoSecondWindow(): void
    {
        $requests = [];
        $http = $this->mockClient([
            $this->json($this->fixture('search_page1.json')),
            $this->json($this->fixture('search_page2.json')),
        ], $requests);

        (new MyAnonamouseClient($http, new FakeMyAnonamouseSettings($this->config()), new NullLogger(), 0))
            ->fetchFreeleech(13);

        self::assertCount(2, $requests, 'found=150 is fully covered by two pages — there is nothing older to ask for');
        foreach ($requests as $request) {
            self::assertStringNotContainsString('tor[endDate]', urldecode($request['body']));
        }
    }

    public function testTheVipWalkStopsAtItsWindowCapEvenWhileRowsKeepComing(): void
    {
        $requests = [];
        $window = 0;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests, &$window): MockResponse {
            $requests[] = ['body' => is_string($options['body'] ?? null) ? $options['body'] : ''];
            // Every window yields a fresh full page and then runs dry, so only the cap
            // can end the walk.
            if (\count($requests) % 2 === 0) {
                return $this->searchPage([], 100);
            }
            $window++;

            return $this->searchPage(range($window * 1000, $window * 1000 + 99), 0);
        });

        $releases = (new MyAnonamouseClient($http, new FakeMyAnonamouseSettings($this->config()), new NullLogger(), 0))
            ->fetchFreeleech(13, 'fl-VIP');

        self::assertCount(20, $requests, 'ten VIP windows of one page plus its empty follow-up');
        self::assertCount(1000, $releases, 'the VIP catalog is truncated to its newest slice, not walked to the end');
    }

    public function testMaxItemsStopsTheWalkAndTruncatesToExactlyTheNewestSlice(): void
    {
        $requests = [];
        $http = $this->mockClient([
            $this->searchPage(range(1, 100), 0),
            $this->searchPage(range(101, 200), 100),
            $this->searchPage(range(201, 300), 200),
        ], $requests);

        $releases = (new MyAnonamouseClient($http, new FakeMyAnonamouseSettings($this->config()), new NullLogger(), 0))
            ->fetchFreeleech(13, 'fl-VIP', maxItems: 150);

        self::assertCount(2, $requests, 'the cap is reached on page two, so page three is never asked for');
        self::assertCount(150, $releases, 'the list is truncated to exactly the cap');
        // The walk is newest-first, so the truncation keeps the newest rows.
        self::assertSame(range(1, 150), array_map(static fn ($release) => $release->mamTorrentId, $releases));
    }

    public function testMaxItemsSatisfiedByOnePageEndsTheWalkAfterThatPage(): void
    {
        $requests = [];
        $http = $this->mockClient([
            $this->searchPage(range(1, 100), 0),
            $this->searchPage(range(101, 200), 100),
        ], $requests);

        $releases = (new MyAnonamouseClient($http, new FakeMyAnonamouseSettings($this->config()), new NullLogger(), 0))
            ->fetchFreeleech(13, 'fl-VIP', maxItems: 100);

        self::assertCount(1, $requests, 'a cap the first page already covers buys no second request');
        self::assertCount(100, $releases);
    }

    public function testACapLargerThanThePoolChangesNothingAndANullCapIsTheFullWalk(): void
    {
        $requests = [];
        $http = $this->mockClient([
            $this->json($this->fixture('search_page1.json')),
            $this->json($this->fixture('search_page2.json')),
        ], $requests);

        $capped = (new MyAnonamouseClient($http, new FakeMyAnonamouseSettings($this->config()), new NullLogger(), 0))
            ->fetchFreeleech(13, 'fl', maxItems: 5000);

        self::assertCount(2, $requests, 'found=150 still ends the sweep on its own');
        self::assertCount(3, $capped);

        $requests = [];
        $http = $this->mockClient([
            $this->json($this->fixture('search_page1.json')),
            $this->json($this->fixture('search_page2.json')),
        ], $requests);

        $uncapped = (new MyAnonamouseClient($http, new FakeMyAnonamouseSettings($this->config()), new NullLogger(), 0))
            ->fetchFreeleech(13, 'fl', maxItems: null);

        self::assertCount(2, $requests);
        self::assertEquals($capped, $uncapped, 'null is the behaviour the caller had before the cap existed');
    }

    public function testANonPositiveCapAsksMamForNothing(): void
    {
        $requests = [];
        $http = $this->mockClient([$this->searchPage(range(1, 100), 0)], $requests);

        self::assertSame(
            [],
            (new MyAnonamouseClient($http, new FakeMyAnonamouseSettings($this->config()), new NullLogger(), 0))
                ->fetchFreeleech(13, 'fl-VIP', maxItems: 0),
        );
        self::assertSame([], $requests);
    }

    public function testAFailedFirstWindowYieldsNothingButALaterFailureKeepsWhatWasCollected(): void
    {
        $requests = [];
        $http = $this->mockClient([new MockResponse('', ['http_code' => 403])], $requests);

        self::assertSame(
            [],
            (new MyAnonamouseClient($http, new FakeMyAnonamouseSettings($this->config()), new NullLogger(), 0))->fetchFreeleech(13),
            'an empty list is how the refresher recognises a failed sweep',
        );

        $requests = [];
        $http = $this->mockClient([
            $this->searchPage(range(1, 100), 0),
            $this->searchPage([], 100),
            new MockResponse('', ['http_code' => 500]),
        ], $requests);

        $releases = (new MyAnonamouseClient($http, new FakeMyAnonamouseSettings($this->config()), new NullLogger(), 0))
            ->fetchFreeleech(13);

        self::assertCount(3, $requests, 'the failed window is not retried');
        self::assertCount(100, $releases, 'window 1 survives the failure of window 2');
    }

    public function testTheSweepLogsItsWindowCountAndTheTotalMamReported(): void
    {
        $requests = [];
        $http = $this->mockClient([
            $this->searchPage(range(1, 100), 0),
            $this->searchPage([], 100),
            $this->searchPage(range(1, 100), 0),
            $this->searchPage([], 100),
        ], $requests);
        $logger = new RecordingLogger();

        (new MyAnonamouseClient($http, new FakeMyAnonamouseSettings($this->config()), $logger, 0))->fetchFreeleech(13);

        $context = $logger->contexts[array_search('MyAnonamouse freeleech sweep finished', $logger->messages, true)];
        self::assertSame('fl', $context['searchType']);
        self::assertSame(13, $context['mainCat']);
        self::assertSame(2, $context['windows']);
        self::assertSame(100, $context['collected']);
        self::assertSame(5000, $context['found'], 'the pool size behind the row cap is what makes the truncation visible');
        self::assertFalse($context['partial']);
    }

    public function testSinglePageStopsWithoutASecondRequestAndMapsEveryField(): void
    {
        $requests = [];
        $http = $this->mockClient([$this->json($this->fixture('search_single_page.json'))], $requests);

        $releases = (new MyAnonamouseClient($http, new FakeMyAnonamouseSettings($this->config()), new NullLogger()))
            ->fetchFreeleech(14);

        self::assertCount(1, $requests, 'start+perpage >= found must end the sweep');
        self::assertCount(1, $releases);

        $release = $releases[0];
        self::assertSame(700001, $release->mamTorrentId);
        self::assertSame('Piranesi [ENG / EPUB]', $release->title, 'the bracket suffix is kept verbatim');
        self::assertSame(['Susanna Clarke'], $release->authors);
        self::assertSame([], $release->narrators);
        self::assertFalse($release->audiobook);
        self::assertSame('Ebooks - Fantasy', $release->catName);
        self::assertSame('ENG', $release->langCode);
        self::assertSame('epub', $release->filetypes);
        self::assertSame((int) round(3.5 * 1024 * 1024), $release->sizeBytes);
        self::assertSame(5, $release->seeders);
        self::assertSame(0, $release->leechers);
        self::assertSame(60, $release->timesCompleted);
        self::assertTrue($release->free);
        self::assertFalse($release->flVip);
        self::assertSame('998877665544', $release->dlHash);
        self::assertSame('https://cdn.myanonamouse.net/thumb/700001.jpg', $release->thumbnailUrl);
        self::assertSame('2026-08-05 12:00:00', $release->addedAt?->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $release->addedAt?->getTimezone()->getName());
    }

    public function testMapsSizesAuthorMapsAndFreeleechFlagVariants(): void
    {
        $requests = [];
        $http = $this->mockClient([
            $this->json($this->fixture('search_page1.json')),
            $this->json($this->fixture('search_page2.json')),
        ], $requests);

        $releases = (new MyAnonamouseClient($http, new FakeMyAnonamouseSettings($this->config()), new NullLogger()))
            ->fetchFreeleech(13);

        [$first, $second, $third] = $releases;

        self::assertSame(['Becky Chambers', 'Ghost Writer'], $first->authors, 'the id=>name map decodes to its values');
        self::assertSame(['Patricia Rodriguez'], $first->narrators);
        self::assertTrue($first->audiobook, 'main_cat 13 is audiobooks');
        self::assertSame((int) round(1.2 * 1024 ** 3), $first->sizeBytes, 'GiB is a binary multiplier');
        self::assertTrue($first->free);
        self::assertFalse($first->flVip);
        self::assertFalse($first->vip);
        self::assertFalse($first->personalFreeleech);
        self::assertSame('https://cdn.myanonamouse.net/thumb/918273.jpg', $first->thumbnailUrl);

        self::assertSame([], $second->authors, 'an empty author_info string is not a parse failure');
        self::assertSame([], $second->narrators, 'a null narrator_info yields no names');
        self::assertSame((int) round(845.5 * 1024 ** 2), $second->sizeBytes, 'MB is tolerated as the binary multiplier');
        self::assertSame('mp3', $second->filetypes, 'the plural filetypes key is accepted too');
        self::assertFalse($second->free);
        self::assertTrue($second->flVip, 'fl_vip=1 marks a VIP-only freeleech');
        self::assertTrue($second->vip);
        self::assertNull($second->thumbnailUrl, 'no thumbnail key means no URL');

        self::assertSame(2048 * 1024, $third->sizeBytes, 'thousands separators are stripped');
        self::assertTrue($third->free, 'a real JSON boolean maps too');
        self::assertTrue($third->personalFreeleech);
        self::assertFalse($third->audiobook, 'main_cat 14 is e-books');
        self::assertSame('https://cdn.myanonamouse.net/thumb/918290.jpg', $third->thumbnailUrl, 'the poster key is accepted');
    }

    public function testTheSearchTypeIsSentVerbatimOnEveryPage(): void
    {
        $requests = [];
        $http = $this->mockClient([
            $this->json($this->fixture('search_vip_page1.json')),
            $this->json($this->fixture('search_vip_page2.json')),
        ], $requests);

        $releases = (new MyAnonamouseClient($http, new FakeMyAnonamouseSettings($this->config()), new NullLogger()))
            ->fetchFreeleech(13, 'fl-VIP');

        self::assertCount(2, $requests);
        self::assertCount(2, $releases);
        foreach ($requests as $request) {
            self::assertStringContainsString('tor[searchType]=fl-VIP', urldecode($request['body']));
        }
        self::assertStringContainsString('tor[startNumber]=100', urldecode($requests[1]['body']), 'paging is unchanged by the search type');
        self::assertTrue($releases[1]->flVip, 'the VIP-only row carries fl_vip');
    }

    public function testTheDefaultSearchTypeIsTheRegularFreeleechSet(): void
    {
        $requests = [];
        $http = $this->mockClient([$this->json($this->fixture('search_single_page.json'))], $requests);

        (new MyAnonamouseClient($http, new FakeMyAnonamouseSettings($this->config()), new NullLogger()))
            ->fetchFreeleech(14);

        self::assertStringContainsString('tor[searchType]=fl', urldecode($requests[0]['body']));
    }

    public function testAnUnknownSearchTypeIsRefusedWithoutARequestOrAnException(): void
    {
        $requests = [];
        $http = $this->mockClient([$this->json($this->fixture('search_single_page.json'))], $requests);
        $logger = new RecordingLogger();

        self::assertSame([], (new MyAnonamouseClient($http, new FakeMyAnonamouseSettings($this->config()), $logger))
            ->fetchFreeleech(13, 'fl; DROP'));

        self::assertSame([], $requests, 'a garbage search type never reaches MAM');
        self::assertStringContainsString('search type is not supported', implode("\n", $logger->messages));
    }

    public function testEmptyResultShapeIsAnEmptyListNotAFailure(): void
    {
        $requests = [];
        $http = $this->mockClient([$this->json($this->fixture('search_empty.json'))], $requests);

        self::assertSame([], (new MyAnonamouseClient($http, new FakeMyAnonamouseSettings($this->config()), new NullLogger()))
            ->fetchFreeleech(14));
        self::assertCount(1, $requests);
    }

    public function testAuthFailureHtmlYieldsNoReleasesAndNoRetry(): void
    {
        $requests = [];
        $http = $this->mockClient([
            new MockResponse($this->fixture('auth_failure.html'), ['response_headers' => ['content-type' => 'text/html']]),
        ], $requests);

        self::assertSame([], (new MyAnonamouseClient($http, new FakeMyAnonamouseSettings($this->config()), new NullLogger()))
            ->fetchFreeleech(13));
        self::assertCount(1, $requests, 'a dead cookie must never be hammered');
    }

    public function testForbiddenResponseYieldsNoReleases(): void
    {
        $requests = [];
        $http = $this->mockClient([new MockResponse('', ['http_code' => 403])], $requests);

        self::assertSame([], (new MyAnonamouseClient($http, new FakeMyAnonamouseSettings($this->config()), new NullLogger()))
            ->fetchFreeleech(13));
    }

    public function testTestConnectionReportsTheAccountOnSuccess(): void
    {
        $requests = [];
        $http = $this->mockClient([$this->json($this->fixture('user_info.json'))], $requests);

        $result = (new MyAnonamouseClient($http, new FakeMyAnonamouseSettings($this->config()), new NullLogger()))
            ->testConnection();

        self::assertTrue($result['ok']);
        self::assertSame('bookmouse', $result['username']);
        self::assertSame('Elite VIP', $result['class']);
        self::assertSame('12.34', $result['ratio']);
        self::assertTrue($result['isVip'], 'Elite VIP is a VIP class');
        self::assertStringContainsString('bookmouse', $result['message']);
        self::assertStringContainsString('/jsonLoad.php?snatch_summary', $requests[0]['url']);
        self::assertSame('GET', $requests[0]['method']);
    }

    public function testTestConnectionFailsOnAnAuthFailurePage(): void
    {
        $requests = [];
        $http = $this->mockClient([
            new MockResponse($this->fixture('auth_failure.html'), ['response_headers' => ['content-type' => 'text/html']]),
        ], $requests);

        $result = (new MyAnonamouseClient($http, new FakeMyAnonamouseSettings($this->config()), new NullLogger()))
            ->testConnection();

        self::assertFalse($result['ok']);
        self::assertNotSame('', $result['message']);
        self::assertArrayNotHasKey('username', $result);
    }

    public function testTestConnectionFailsWithoutACookieAndMakesNoRequest(): void
    {
        $requests = [];
        $http = $this->mockClient([], $requests);
        $client = new MyAnonamouseClient($http, new FakeMyAnonamouseSettings($this->config(), null), new NullLogger());

        $result = $client->testConnection();

        self::assertFalse($result['ok']);
        self::assertCount(0, $requests);
        self::assertFalse($client->isConfigured());
    }

    public function testIsConfiguredNeedsBothTheMasterSwitchAndACookie(): void
    {
        $http = new MockHttpClient([]);

        self::assertTrue((new MyAnonamouseClient($http, new FakeMyAnonamouseSettings($this->config()), new NullLogger()))->isConfigured());
        self::assertFalse((new MyAnonamouseClient($http, new FakeMyAnonamouseSettings($this->config(enabled: false)), new NullLogger()))->isConfigured());
        self::assertFalse((new MyAnonamouseClient($http, new FakeMyAnonamouseSettings($this->config(), ''), new NullLogger()))->isConfigured());
    }

    public function testFetchFreeleechIsSkippedWhenTheIntegrationIsDisabled(): void
    {
        $requests = [];
        $http = $this->mockClient([], $requests);

        self::assertSame([], (new MyAnonamouseClient($http, new FakeMyAnonamouseSettings($this->config(enabled: false)), new NullLogger()))
            ->fetchFreeleech(13));
        self::assertCount(0, $requests);
    }

    public function testARotatedSessionCookieIsPersistedAndUsedOnTheNextCall(): void
    {
        $requests = [];
        $http = $this->mockClient([
            $this->json($this->fixture('search_single_page.json'), ['set-cookie' => 'mam_id=rotated-value; Path=/; HttpOnly']),
            $this->json($this->fixture('user_info.json')),
        ], $requests);
        $settings = new FakeMyAnonamouseSettings($this->config());
        $client = new MyAnonamouseClient($http, $settings, new NullLogger());

        $client->fetchFreeleech(14);
        self::assertSame(['rotated-value'], $settings->rotations);

        $client->fetchUserInfo();
        self::assertStringContainsString('mam_id=rotated-value', implode("\n", $requests[1]['headers']));
    }

    public function testAnUnchangedSessionCookieIsNotPersistedAgain(): void
    {
        $requests = [];
        $http = $this->mockClient([
            $this->json($this->fixture('search_single_page.json'), ['set-cookie' => 'mam_id=session-cookie-value; Path=/']),
        ], $requests);
        $settings = new FakeMyAnonamouseSettings($this->config());

        (new MyAnonamouseClient($http, $settings, new NullLogger()))->fetchFreeleech(14);

        self::assertSame([], $settings->rotations);
    }

    public function testFetchUserInfoReturnsNullOnFailureAndFlagsNonVipClasses(): void
    {
        $requests = [];
        $http = $this->mockClient([
            new MockResponse('', ['http_code' => 500]),
            $this->json('{"username":"mouse","classname":"Power User","ratio":3.5}'),
        ], $requests);
        $client = new MyAnonamouseClient($http, new FakeMyAnonamouseSettings($this->config()), new NullLogger());

        self::assertNull($client->fetchUserInfo());

        $info = $client->fetchUserInfo();
        self::assertSame('mouse', $info['username']);
        self::assertSame('Power User', $info['class'], 'the classname spelling is accepted');
        self::assertSame(3.5, $info['ratio']);
        self::assertFalse($info['isVip']);
    }

    public function testDynamicSeedboxUpdateHitsTheTrackerSubdomainAndReportsSuccess(): void
    {
        $requests = [];
        $http = $this->mockClient([
            $this->json('{"Success":true,"msg":"Session IP updated"}'),
            $this->json('{"Success":false,"msg":"No change"}'),
            new MockResponse($this->fixture('auth_failure.html'), ['response_headers' => ['content-type' => 'text/html']]),
        ], $requests);
        $client = new MyAnonamouseClient($http, new FakeMyAnonamouseSettings($this->config()), new NullLogger());

        self::assertTrue($client->updateDynamicSeedboxIp());
        self::assertSame('https://t.myanonamouse.net/json/dynamicSeedbox.php', $requests[0]['url']);
        self::assertFalse($client->updateDynamicSeedboxIp());
        self::assertFalse($client->updateDynamicSeedboxIp(), 'an auth failure is not a successful update');
    }

    public function testTheConfiguredProxyIsAppliedToMamTraffic(): void
    {
        $requests = [];
        $http = $this->mockClient([$this->json($this->fixture('search_single_page.json'))], $requests);

        (new MyAnonamouseClient($http, new FakeMyAnonamouseSettings($this->config(proxy: 'socks5://127.0.0.1:1080')), new NullLogger()))
            ->fetchFreeleech(14);

        self::assertSame('socks5://127.0.0.1:1080', $requests[0]['proxy']);
    }

    public function testSearchReleasesSendsOneQueryScopedToThePlansCategory(): void
    {
        $requests = [];
        $http = $this->mockClient([$this->json($this->fixture('search_query.json'))], $requests);

        $releases = (new MyAnonamouseClient($http, new FakeMyAnonamouseSettings($this->config()), new NullLogger()))
            ->searchReleases($this->plan(), ProwlarrConfig::METHOD_CATEGORIES);

        self::assertCount(1, $requests, 'an on-demand search is a single request, never a walk');
        self::assertSame('POST', $requests[0]['method']);
        self::assertStringEndsWith('/tor/js/loadSearchJSONbasic.php', $requests[0]['url']);

        $body = urldecode($requests[0]['body']);
        self::assertStringContainsString('tor[text]=The Hobbit J. R. R. Tolkien', $body, 'the query is the plan title plus author');
        self::assertStringContainsString('tor[srchIn][title]=true', $body);
        self::assertStringContainsString('tor[srchIn][author]=true', $body);
        self::assertStringContainsString('tor[srchIn][narrator]=true', $body);
        self::assertStringContainsString('tor[searchType]=all', $body);
        self::assertStringContainsString('tor[sortType]=default', $body);
        self::assertStringContainsString('tor[perpage]=100', $body);
        self::assertStringContainsString('thumbnails=1', $body);
        self::assertStringContainsString('dlLink=1', $body, 'without the dlLink opt-in MAM omits every row\'s dl download hash');
        self::assertStringContainsString('tor[main_cat][0]=13', $body, 'an audiobook plan scopes to MAM main category 13');

        self::assertCount(3, $releases, 'categories mode trusts MAMs own scoping — nothing is dropped client-side');
        self::assertSame([555001, 555002, 555003], array_map(static fn ($release) => $release->mamTorrentId, $releases));

        $requests = [];
        $http = $this->mockClient([$this->json($this->fixture('search_query.json'))], $requests);

        (new MyAnonamouseClient($http, new FakeMyAnonamouseSettings($this->config()), new NullLogger()))
            ->searchReleases($this->plan(contentType: ReleaseCandidate::CONTENT_EBOOK), ProwlarrConfig::METHOD_CATEGORIES);

        self::assertStringContainsString('tor[main_cat][0]=14', urldecode($requests[0]['body']), 'an ebook plan scopes to main category 14');
    }

    public function testSearchReleasesRawSendsNoCategoryFilter(): void
    {
        $requests = [];
        $http = $this->mockClient([$this->json($this->fixture('search_query.json'))], $requests);

        $releases = (new MyAnonamouseClient($http, new FakeMyAnonamouseSettings($this->config()), new NullLogger()))
            ->searchReleases($this->plan(), ProwlarrConfig::METHOD_RAW);

        self::assertStringNotContainsString('tor[main_cat]', urldecode($requests[0]['body']));
        self::assertCount(3, $releases);
    }

    public function testSearchReleasesFilteredDropsRowsThatContradictThePlansContentType(): void
    {
        $requests = [];
        $http = $this->mockClient([$this->json($this->fixture('search_query.json'))], $requests);

        $releases = (new MyAnonamouseClient($http, new FakeMyAnonamouseSettings($this->config()), new NullLogger()))
            ->searchReleases($this->plan(), ProwlarrConfig::METHOD_FILTERED);

        self::assertStringNotContainsString('tor[main_cat]', urldecode($requests[0]['body']), 'filtered mode sends no category filter either');
        self::assertSame([555001, 555003], array_map(static fn ($release) => $release->mamTorrentId, $releases), 'the ebook row contradicts the audiobook plan');

        $requests = [];
        $http = $this->mockClient([$this->json($this->fixture('search_query.json'))], $requests);

        $releases = (new MyAnonamouseClient($http, new FakeMyAnonamouseSettings($this->config()), new NullLogger()))
            ->searchReleases($this->plan(contentType: ReleaseCandidate::CONTENT_EBOOK), ProwlarrConfig::METHOD_FILTERED);

        self::assertSame([555002], array_map(static fn ($release) => $release->mamTorrentId, $releases), 'the audiobook rows contradict the ebook plan');
    }

    public function testSearchReleasesMapsTheGrabFieldsAndTheirHelpers(): void
    {
        $requests = [];
        $http = $this->mockClient([$this->json($this->fixture('search_query.json'))], $requests);

        [$first, $second, $third] = (new MyAnonamouseClient($http, new FakeMyAnonamouseSettings($this->config()), new NullLogger()))
            ->searchReleases($this->plan(), ProwlarrConfig::METHOD_RAW);

        self::assertTrue($first->free);
        self::assertSame(
            'https://www.myanonamouse.net/tor/download.php/aa11bb22cc33',
            $first->downloadUrl('https://www.myanonamouse.net/'),
            'the download URL is built from the rows dl hash, base slash normalized',
        );
        self::assertTrue($first->isFreeForUser(false), 'sitewide freeleech is free for everyone');

        self::assertTrue($second->flVip);
        self::assertFalse($second->isFreeForUser(false), 'a VIP freeleech costs a non-VIP account');
        self::assertTrue($second->isFreeForUser(true));

        self::assertTrue($third->personalFreeleech);
        self::assertTrue($third->isFreeForUser(false), 'a personal freeleech is free regardless of class');

        $noHash = new MamRelease(
            mamTorrentId: 1,
            title: 'Hashless',
            authors: [],
            narrators: [],
            audiobook: false,
            catName: null,
            langCode: null,
            filetypes: null,
            sizeBytes: null,
            seeders: 0,
            leechers: 0,
            timesCompleted: 0,
            vip: false,
            flVip: false,
            free: false,
            personalFreeleech: false,
            dlHash: null,
            thumbnailUrl: null,
            addedAt: null,
        );
        self::assertNull($noHash->downloadUrl('https://www.myanonamouse.net'), 'a row without a dl hash cannot be grabbed');
    }

    public function testSearchReleasesSendsTheIsbnAsTextWhenThePlanHasNothingElse(): void
    {
        $requests = [];
        $http = $this->mockClient([$this->json($this->fixture('search_query.json'))], $requests);

        (new MyAnonamouseClient($http, new FakeMyAnonamouseSettings($this->config()), new NullLogger()))
            ->searchReleases($this->plan(title: '', author: '', isbns: ['9780618968633']), ProwlarrConfig::METHOD_CATEGORIES);

        self::assertStringContainsString('tor[text]=9780618968633', urldecode($requests[0]['body']), 'MAM has no ISBN param — the ISBN rides in as free text');
    }

    public function testSearchReleasesRespectsTheLimitBySlicing(): void
    {
        $requests = [];
        $http = $this->mockClient([$this->searchPage(range(1, 100), 0)], $requests);

        $releases = (new MyAnonamouseClient($http, new FakeMyAnonamouseSettings($this->config()), new NullLogger()))
            ->searchReleases($this->plan(), ProwlarrConfig::METHOD_RAW, limit: 5);

        self::assertCount(1, $requests, 'the limit never buys a second request — MAM is asked for a full page regardless');
        self::assertStringContainsString('tor[perpage]=100', urldecode($requests[0]['body']));
        self::assertSame(range(1, 5), array_map(static fn ($release) => $release->mamTorrentId, $releases));
    }

    public function testSearchReleasesRefusesAnUnknownMethodWithoutARequest(): void
    {
        $requests = [];
        $http = $this->mockClient([$this->json($this->fixture('search_query.json'))], $requests);
        $logger = new RecordingLogger();

        self::assertSame([], (new MyAnonamouseClient($http, new FakeMyAnonamouseSettings($this->config()), $logger))
            ->searchReleases($this->plan(), 'categories; DROP'));

        self::assertSame([], $requests, 'a garbage method never reaches MAM');
        self::assertStringContainsString('search method is not supported', implode("\n", $logger->messages));
    }

    public function testSearchReleasesIsSkippedWhenUnconfiguredOrAskedForNothing(): void
    {
        $requests = [];
        $http = $this->mockClient([], $requests);

        self::assertSame([], (new MyAnonamouseClient($http, new FakeMyAnonamouseSettings($this->config(enabled: false)), new NullLogger()))
            ->searchReleases($this->plan(), ProwlarrConfig::METHOD_CATEGORIES));
        self::assertSame([], (new MyAnonamouseClient($http, new FakeMyAnonamouseSettings($this->config()), new NullLogger()))
            ->searchReleases($this->plan(), ProwlarrConfig::METHOD_CATEGORIES, limit: 0));
        self::assertSame([], $requests);
    }

    public function testSpendWedgeHitsBonusBuyAndReportsSuccess(): void
    {
        $requests = [];
        $http = $this->mockClient([$this->json($this->fixture('bonus_buy_success.json'))], $requests);

        $ok = (new MyAnonamouseClient($http, new FakeMyAnonamouseSettings($this->config()), new NullLogger()))
            ->spendWedge(555003);

        self::assertTrue($ok);
        self::assertCount(1, $requests, 'a wedge spend is a single request, never retried');
        self::assertSame('GET', $requests[0]['method']);
        self::assertSame('https://www.myanonamouse.net/json/bonusBuy.php/?spendtype=personalFL&torrentid=555003', $requests[0]['url']);
    }

    public function testSpendWedgeRefusalIsLoggedAndFalseNeverThrown(): void
    {
        $requests = [];
        $http = $this->mockClient([
            $this->json($this->fixture('bonus_buy_refused.json')),
            new MockResponse('', ['http_code' => 403]),
        ], $requests);
        $logger = new RecordingLogger();
        $client = new MyAnonamouseClient($http, new FakeMyAnonamouseSettings($this->config()), $logger);

        self::assertFalse($client->spendWedge(555003), 'a lowercase success:false is a refusal too');
        self::assertStringContainsString('Not enough seedbonus', implode("\n", array_map('json_encode', $logger->contexts)));
        self::assertContains('warning', $logger->levels);

        self::assertFalse($client->spendWedge(555003), 'an auth failure degrades to false');
        self::assertCount(2, $requests);
    }

    public function testDownloadTorrentFileReturnsTheRawBytes(): void
    {
        $torrent = 'd8:announce40:https://t.myanonamouse.net/tracker.php4:infod4:name5:book1ee';
        $requests = [];
        $http = $this->mockClient([
            new MockResponse($torrent, ['response_headers' => ['content-type' => 'application/x-bittorrent']]),
        ], $requests);

        $bytes = (new MyAnonamouseClient($http, new FakeMyAnonamouseSettings($this->config()), new NullLogger()))
            ->downloadTorrentFile('aa11bb22cc33');

        self::assertSame($torrent, $bytes);
        self::assertSame('https://www.myanonamouse.net/tor/download.php/aa11bb22cc33', $requests[0]['url']);
        self::assertStringContainsString('mam_id=session-cookie-value', implode("\n", $requests[0]['headers']));
    }

    public function testDownloadTorrentFileRejectsHtmlLoginPagesAndFailures(): void
    {
        $requests = [];
        $http = $this->mockClient([
            new MockResponse($this->fixture('auth_failure.html'), ['response_headers' => ['content-type' => 'text/html']]),
            new MockResponse("\n<html><body>Please log in</body></html>", ['response_headers' => ['content-type' => 'application/octet-stream']]),
            new MockResponse('', ['http_code' => 403]),
        ], $requests);
        $client = new MyAnonamouseClient($http, new FakeMyAnonamouseSettings($this->config()), new NullLogger());

        self::assertNull($client->downloadTorrentFile('deadbeef'), 'a text/html login page is not a torrent');
        self::assertNull($client->downloadTorrentFile('deadbeef'), 'an HTML body is rejected whatever the content type claims');
        self::assertNull($client->downloadTorrentFile('deadbeef'), 'a 403 is a failure, not bytes');
        self::assertCount(3, $requests, 'no failure is retried');

        self::assertNull($client->downloadTorrentFile('  '), 'an empty hash never reaches MAM');
        self::assertCount(3, $requests);
    }

    public function testDownloadTorrentFilePersistsARotatedCookieLikeEveryOtherCall(): void
    {
        $requests = [];
        $http = $this->mockClient([
            new MockResponse('d4:infod4:name1:xee', ['response_headers' => [
                'content-type' => 'application/x-bittorrent',
                'set-cookie'   => 'mam_id=rotated-value; Path=/; HttpOnly',
            ]]),
            new MockResponse('d4:infod4:name1:yee', ['response_headers' => ['content-type' => 'application/x-bittorrent']]),
        ], $requests);
        $settings = new FakeMyAnonamouseSettings($this->config());
        $client = new MyAnonamouseClient($http, $settings, new NullLogger());

        $client->downloadTorrentFile('aa11bb22cc33');
        self::assertSame(['rotated-value'], $settings->rotations);

        $client->downloadTorrentFile('dd44ee55ff66');
        self::assertStringContainsString('mam_id=rotated-value', implode("\n", $requests[1]['headers']));
    }

    public function testFetchUserInfoParsesTheExtendedAccountSummary(): void
    {
        $requests = [];
        $http = $this->mockClient([$this->json($this->fixture('user_info.json'))], $requests);

        $info = (new MyAnonamouseClient($http, new FakeMyAnonamouseSettings($this->config()), new NullLogger()))
            ->fetchUserInfo();

        // The connection-test contract stays untouched…
        self::assertSame('bookmouse', $info['username']);
        self::assertSame('Elite VIP', $info['class']);
        self::assertSame('12.34', $info['ratio']);
        self::assertTrue($info['isVip']);
        // …and the stats extras ride alongside.
        self::assertSame(51234, $info['seedbonus']);
        self::assertSame(0, $info['unsatCount']);
        self::assertSame(5, $info['unsatLimit']);
        self::assertSame('4.2 TiB', $info['uploaded']);
        self::assertSame('349.1 GiB', $info['downloaded']);
        self::assertNull($info['wedges'], 'the summary does not report wedges — null, never a fabricated 0');
        self::assertNull($info['vipUntil']);
    }

    public function testFetchUserInfoReadsWedgesAndVipExpiryWhenPresent(): void
    {
        $requests = [];
        $http = $this->mockClient([
            $this->json('{"username":"mouse","classname":"VIP","ratio":1.0,"seedbonus":"1000","unsat":{"count":"2","limit":"10"},"fl_wedges":"7","vip_until":"2027-01-01 00:00:00","uploaded":"1 TiB","downloaded":"10 GiB"}'),
        ], $requests);

        $info = (new MyAnonamouseClient($http, new FakeMyAnonamouseSettings($this->config()), new NullLogger()))
            ->fetchUserInfo();

        self::assertSame(1000, $info['seedbonus'], 'numeric strings are coerced');
        self::assertSame(2, $info['unsatCount']);
        self::assertSame(10, $info['unsatLimit']);
        self::assertSame(7, $info['wedges'], 'the fl_wedges spelling is accepted');
        self::assertSame('2027-01-01 00:00:00', $info['vipUntil']);
    }

    public function testTransportErrorsNeverEscapeTheClient(): void
    {
        $http = new MockHttpClient(static function (): MockResponse {
            throw new \Symfony\Component\HttpClient\Exception\TransportException('connection refused');
        });
        $client = new MyAnonamouseClient($http, new FakeMyAnonamouseSettings($this->config()), new NullLogger());

        self::assertSame([], $client->fetchFreeleech(13));
        self::assertNull($client->fetchUserInfo());
        self::assertFalse($client->updateDynamicSeedboxIp());
        self::assertSame([], $client->searchReleases($this->plan(), ProwlarrConfig::METHOD_CATEGORIES));
        self::assertFalse($client->spendWedge(1));
        self::assertNull($client->downloadTorrentFile('deadbeef'));
    }

    /**
     * @param list<MockResponse>                                                           $responses
     * @param list<array{method: string, url: string, body: string, headers: list<string>, proxy: ?string}> $requests
     */
    private function mockClient(array $responses, array &$requests): MockHttpClient
    {
        $index = 0;

        return new MockHttpClient(static function (string $method, string $url, array $options) use ($responses, &$index, &$requests): MockResponse {
            $requests[] = [
                'method'  => $method,
                'url'     => $url,
                'body'    => is_string($options['body'] ?? null) ? $options['body'] : '',
                'headers' => $options['headers'] ?? [],
                'proxy'   => $options['proxy'] ?? null,
            ];

            return $responses[$index++];
        });
    }

    /**
     * A search page of synthetic rows, newest first, whose `added` date is derived from
     * the torrent id — so a row repeated across two windows repeats its date too. The
     * `found` total is deliberately far bigger than anything MAM will actually serve,
     * which is the whole reason the client has to walk backwards by date.
     *
     * @param list<int> $ids
     */
    private function searchPage(array $ids, int $start, int $found = 5000): MockResponse
    {
        $rows = array_map(fn (int $id): array => [
            'id'       => $id,
            'title'    => 'Row ' . $id . ' [ENG / EPUB]',
            'main_cat' => 13,
            'size'     => '1.0 MiB',
            'added'    => $this->addedFor($id),
            'free'     => 1,
            'dl'       => 'hash' . $id,
        ], $ids);

        return $this->json((string) json_encode(
            ['data' => $rows, 'found' => $found, 'perpage' => 100, 'start' => $start],
            JSON_THROW_ON_ERROR,
        ));
    }

    /** Six hours older per id, so higher ids are older rows. */
    private function addedFor(int $id): string
    {
        return (new \DateTimeImmutable('2026-08-08 00:00:00', new \DateTimeZone('UTC')))
            ->modify('-' . ($id * 6) . ' hours')
            ->format('Y-m-d H:i:s');
    }

    private function dateFor(int $id): string
    {
        return substr($this->addedFor($id), 0, 10);
    }

    private function json(string $body, array $headers = []): MockResponse
    {
        return new MockResponse($body, ['response_headers' => ['content-type' => 'application/json'] + $headers]);
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 2) . '/Fixtures/responses/mam/' . $name);
    }

    /**
     * A release-search plan the way the fulfilment pipeline builds one: title
     * variants + author, optional ISBNs, content type defaulting to audiobook.
     *
     * @param list<string> $isbns
     */
    private function plan(
        string $title = 'The Hobbit',
        string $author = 'J. R. R. Tolkien',
        array $isbns = [],
        string $contentType = ReleaseCandidate::CONTENT_AUDIOBOOK,
    ): ReleaseSearchPlan {
        return new ReleaseSearchPlan(
            book: new Book('hardcover', 'hb-1', $title !== '' ? $title : 'Untitled'),
            isbnCandidates: $isbns,
            author: $author,
            titleVariants: $title === '' ? [] : [$title],
            contentType: $contentType,
        );
    }

    private function config(bool $enabled = true, ?string $proxy = null): MyAnonamouseConfig
    {
        return new MyAnonamouseConfig(
            enabled: $enabled,
            baseUrl: 'https://www.myanonamouse.net',
            showOnHomepage: true,
            showBrowseShelf: true,
            bookFormatEnabled: true,
            audiobookFormatEnabled: true,
            minSeeders: 0,
            fetchVipFreeleech: false,
            dynamicSeedboxUpdate: false,
            proxyUrl: $proxy,
        );
    }
}

/**
 * Keeps the messages a refused call logs, so a degradation can be told apart from a
 * silent one.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $messages = [];

    /** @var list<array<string, mixed>> */
    public array $contexts = [];

    /** @var list<string> */
    public array $levels = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->messages[] = (string) $message;
        $this->contexts[] = $context;
        $this->levels[] = (string) $level;
    }
}
