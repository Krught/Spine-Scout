<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BlockedReleaseRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A per-book blocklist entry for a release that failed in a way that proves the
 * release itself is bad (dead/junk content, disallowed downloaded format, …).
 * Automatic re-search sweeps skip blocked releases so selection moves on to the
 * next candidate instead of retrying the same broken one every cycle.
 *
 * Keyed by (book, source, sourceId); the downloadUrl/magnet and the torrent
 * infohash (client ref) are stored alongside so a candidate can also be matched
 * by URL when its record id differs across indexers. Entries expire after
 * {@see TTL_DAYS} — a release that was junk a month ago may have been replaced.
 */
#[ORM\Entity(repositoryClass: BlockedReleaseRepository::class)]
#[ORM\Table(name: 'blocked_releases')]
#[ORM\UniqueConstraint(name: 'blocked_releases_book_id_source_source_id_uniq', columns: ['book_id', 'source', 'source_id'])]
#[ORM\Index(name: 'blocked_releases_book_id_expires_at_idx', columns: ['book_id', 'expires_at'])]
class BlockedRelease
{
    /** Blocks auto-expire after this many days. */
    public const TTL_DAYS = 30;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Book::class)]
    #[ORM\JoinColumn(name: 'book_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Book $book;

    /** Release source key ('libgen', 'annas_archive', 'torrent', …). */
    #[ORM\Column(length: 40)]
    private string $source;

    /** Stable per-source record id (md5/record id, Prowlarr guid, …). */
    #[ORM\Column(length: 255)]
    private string $sourceId;

    #[ORM\Column(length: 16)]
    private string $protocol;

    /** Candidate download URL / magnet link, when known. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $url = null;

    /** Download-client reference (torrent infohash), when known. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $clientRef = null;

    /** Human-readable failure that earned the block. */
    #[ORM\Column(type: Types::TEXT)]
    private string $reason;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    public function __construct(
        Book $book,
        string $source,
        string $sourceId,
        string $protocol,
        ?string $url,
        ?string $clientRef,
        string $reason,
    ) {
        $this->book = $book;
        $this->source = $source;
        $this->sourceId = $sourceId;
        $this->protocol = $protocol;
        $this->url = $url;
        $this->clientRef = $clientRef;
        $this->reason = $reason;
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = $this->createdAt->modify(sprintf('+%d days', self::TTL_DAYS));
    }

    public function getId(): ?int { return $this->id; }
    public function getBook(): Book { return $this->book; }
    public function getSource(): string { return $this->source; }
    public function getSourceId(): string { return $this->sourceId; }
    public function getProtocol(): string { return $this->protocol; }
    public function getUrl(): ?string { return $this->url; }
    public function getClientRef(): ?string { return $this->clientRef; }
    public function getReason(): string { return $this->reason; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
}
