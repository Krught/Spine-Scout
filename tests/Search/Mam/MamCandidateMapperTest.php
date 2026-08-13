<?php

declare(strict_types=1);

namespace App\Tests\Search\Mam;

use App\Integration\MyAnonamouse\MamRelease;
use App\Search\Mam\MamCandidateMapper;
use App\Search\Source\ReleaseCandidate;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the MAM row → ReleaseCandidate mapping: the identity fields the
 * grab path depends on (source/sourceId/protocol/downloadUrl and the extra['mam']
 * bag), the flag chips, the multi-valued filetype handling, and the content-type
 * classification off MAM's main category.
 */
final class MamCandidateMapperTest extends TestCase
{
    private const BASE_URL = 'https://www.myanonamouse.net';

    public function testMapsIdentityAndTorrentFacts(): void
    {
        $c = MamCandidateMapper::map($this->release(), self::BASE_URL);

        self::assertSame('mam', $c->source);
        self::assertSame('123456', $c->sourceId);
        self::assertSame('Red Rising [ENG / EPUB]', $c->title);
        self::assertSame(ReleaseCandidate::PROTOCOL_TORRENT, $c->protocol);
        self::assertSame('MyAnonamouse', $c->indexer);
        self::assertSame('Pierce Brown, Someone Else', $c->author);
        self::assertSame(123_456_789, $c->sizeBytes);
        self::assertSame(42, $c->seeders);
        self::assertSame(97, $c->downloads);
        self::assertSame(self::BASE_URL . '/tor/download.php/dl-hash-abc', $c->downloadUrl);
        self::assertSame(self::BASE_URL . '/t/123456', $c->infoUrl);

        self::assertSame(3, $c->extra['leechers']);
        self::assertSame(['Ebooks - Fiction'], $c->extra['categories']);
        self::assertSame('2024-05-01', $c->extra['publishDate']);
        self::assertSame('epub mobi pdf', $c->extra['filetypes']);
    }

    public function testExtraMamBagCarriesTheGrabFacts(): void
    {
        $c = MamCandidateMapper::map(
            $this->release(free: true, flVip: true, personalFreeleech: true),
            self::BASE_URL,
        );

        self::assertSame([
            'torrentId'         => 123456,
            'dlHash'            => 'dl-hash-abc',
            'free'              => true,
            'flVip'             => true,
            'personalFreeleech' => true,
        ], $c->extra['mam']);
    }

    public function testFormatIsTheFirstFiletypeTokenLowercased(): void
    {
        self::assertSame('epub', MamCandidateMapper::map($this->release(filetypes: 'EPUB MOBI PDF'), self::BASE_URL)->format);
        self::assertSame('m4b', MamCandidateMapper::map($this->release(filetypes: 'm4b'), self::BASE_URL)->format);
        self::assertNull(MamCandidateMapper::map($this->release(filetypes: null), self::BASE_URL)->format);
        self::assertNull(MamCandidateMapper::map($this->release(filetypes: '  '), self::BASE_URL)->format);
    }

    public function testContentTypeFollowsTheMamMainCategory(): void
    {
        $ebook = MamCandidateMapper::map($this->release(audiobook: false), self::BASE_URL);
        self::assertSame(ReleaseCandidate::CONTENT_EBOOK, $ebook->contentType);
        self::assertSame(ReleaseCandidate::CONTENT_EBOOK, $ebook->extra['type']);

        $audio = MamCandidateMapper::map($this->release(audiobook: true), self::BASE_URL);
        self::assertSame(ReleaseCandidate::CONTENT_AUDIOBOOK, $audio->contentType);
        self::assertSame(ReleaseCandidate::CONTENT_AUDIOBOOK, $audio->extra['type']);
    }

    public function testFlagVariants(): void
    {
        self::assertSame([], $this->flags($this->release()));
        self::assertSame(['freeleech'], $this->flags($this->release(free: true)));
        self::assertSame(['vip_freeleech'], $this->flags($this->release(flVip: true)));
        self::assertSame(['personal_freeleech'], $this->flags($this->release(personalFreeleech: true)));
        self::assertSame(['vip'], $this->flags($this->release(vip: true)));
        self::assertSame(
            ['freeleech', 'vip_freeleech', 'personal_freeleech', 'vip'],
            $this->flags($this->release(free: true, flVip: true, personalFreeleech: true, vip: true)),
        );
    }

    public function testRowWithoutDlHashHasNoDownloadUrlButKeepsAnEmptyHash(): void
    {
        $c = MamCandidateMapper::map($this->release(dlHash: null), self::BASE_URL);

        self::assertNull($c->downloadUrl);
        self::assertSame('', $c->extra['mam']['dlHash']);
    }

    public function testMapAllPreservesOrder(): void
    {
        $mapped = MamCandidateMapper::mapAll([
            $this->release(id: 1, title: 'First'),
            $this->release(id: 2, title: 'Second'),
        ], self::BASE_URL);

        self::assertCount(2, $mapped);
        self::assertSame(['1', '2'], [$mapped[0]->sourceId, $mapped[1]->sourceId]);
        self::assertSame(['First', 'Second'], [$mapped[0]->title, $mapped[1]->title]);
    }

    // --- helpers ----------------------------------------------------------

    /** @return list<string> */
    private function flags(MamRelease $release): array
    {
        return MamCandidateMapper::map($release, self::BASE_URL)->extra['flags'];
    }

    private function release(
        int $id = 123456,
        string $title = 'Red Rising [ENG / EPUB]',
        bool $audiobook = false,
        ?string $filetypes = 'epub mobi pdf',
        bool $vip = false,
        bool $flVip = false,
        bool $free = false,
        bool $personalFreeleech = false,
        ?string $dlHash = 'dl-hash-abc',
    ): MamRelease {
        return new MamRelease(
            mamTorrentId: $id,
            title: $title,
            authors: ['Pierce Brown', 'Someone Else'],
            narrators: [],
            audiobook: $audiobook,
            catName: 'Ebooks - Fiction',
            langCode: 'ENG',
            filetypes: $filetypes,
            sizeBytes: 123_456_789,
            seeders: 42,
            leechers: 3,
            timesCompleted: 97,
            vip: $vip,
            flVip: $flVip,
            free: $free,
            personalFreeleech: $personalFreeleech,
            dlHash: $dlHash,
            thumbnailUrl: null,
            addedAt: new \DateTimeImmutable('2024-05-01 12:34:56', new \DateTimeZone('UTC')),
        );
    }
}
