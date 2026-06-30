<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DownloadJobRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A single attempt to fetch the bytes for a release candidate.
 *
 * One BookRequest can spawn multiple DownloadJobs over its lifetime (first
 * attempt fails, admin picks a different release, etc.). Kept separate from
 * BookRequest because BookRequest is the high-level state machine while
 * DownloadJob is the granular "where is the file right now?" tracker.
 */
#[ORM\Entity(repositoryClass: DownloadJobRepository::class)]
#[ORM\Table(name: 'download_jobs')]
#[ORM\Index(name: 'download_jobs_status_idx', columns: ['status'])]
#[ORM\Index(name: 'download_jobs_request_idx', columns: ['book_request_id'])]
#[ORM\HasLifecycleCallbacks]
class DownloadJob
{
    public const STATUS_QUEUED      = 'queued';
    public const STATUS_RESOLVING   = 'resolving';
    public const STATUS_DOWNLOADING = 'downloading';
    public const STATUS_COMPLETE    = 'complete';
    public const STATUS_ERROR       = 'error';
    public const STATUS_CANCELLED   = 'cancelled';

    public const STATUSES = [
        self::STATUS_QUEUED,
        self::STATUS_RESOLVING,
        self::STATUS_DOWNLOADING,
        self::STATUS_COMPLETE,
        self::STATUS_ERROR,
        self::STATUS_CANCELLED,
    ];

    /** In-flight (non-terminal) states — a job here is being worked or waiting. */
    public const ACTIVE_STATUSES = [
        self::STATUS_QUEUED,
        self::STATUS_RESOLVING,
        self::STATUS_DOWNLOADING,
    ];

    public const TERMINAL_STATUSES = [
        self::STATUS_COMPLETE,
        self::STATUS_ERROR,
        self::STATUS_CANCELLED,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: BookRequest::class)]
    #[ORM\JoinColumn(name: 'book_request_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?BookRequest $bookRequest;

    #[ORM\Column(length: 40)]
    private string $source;

    #[ORM\Column(length: 255)]
    private string $sourceId;

    #[ORM\Column(length: 16)]
    private string $protocol;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $downloadUrl = null;

    /**
     * Ordered candidate download URLs to try, with failover: the handler attempts
     * each in turn until one succeeds. The first is mirrored into downloadUrl for
     * at-a-glance display.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    private array $candidateLinks = [];

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $format = null;

    /**
     * The download client's native handle for an async job — the qBittorrent
     * torrent hash. Null for synchronous HTTP jobs; set by the torrent dispatcher
     * and read by the poller to query status / issue the final move.
     */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $clientRef = null;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?string $sizeBytes = null;

    #[ORM\Column(length: 16, options: ['default' => self::STATUS_QUEUED])]
    private string $status = self::STATUS_QUEUED;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $progress = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $statusMessage = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $filePath = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $source,
        string $sourceId,
        string $protocol,
        ?BookRequest $bookRequest = null,
    ) {
        $now = new \DateTimeImmutable();
        $this->source = $source;
        $this->sourceId = $sourceId;
        $this->protocol = $protocol;
        $this->bookRequest = $bookRequest;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getBookRequest(): ?BookRequest { return $this->bookRequest; }
    public function setBookRequest(?BookRequest $request): self { $this->bookRequest = $request; return $this; }

    public function getSource(): string { return $this->source; }
    public function setSource(string $source): self { $this->source = $source; return $this; }
    public function getSourceId(): string { return $this->sourceId; }
    public function setSourceId(string $sourceId): self { $this->sourceId = $sourceId; return $this; }
    public function getProtocol(): string { return $this->protocol; }
    public function setProtocol(string $protocol): self { $this->protocol = $protocol; return $this; }

    public function getDownloadUrl(): ?string { return $this->downloadUrl; }
    public function setDownloadUrl(?string $url): self { $this->downloadUrl = $url; return $this; }

    /** @return list<string> */
    public function getCandidateLinks(): array { return $this->candidateLinks; }
    /** @param list<string> $links */
    public function setCandidateLinks(array $links): self { $this->candidateLinks = array_values($links); return $this; }

    public function getFormat(): ?string { return $this->format; }
    public function setFormat(?string $format): self { $this->format = $format; return $this; }

    public function getClientRef(): ?string { return $this->clientRef; }
    public function setClientRef(?string $ref): self { $this->clientRef = $ref; return $this; }

    public function getSizeBytes(): ?int
    {
        return $this->sizeBytes === null ? null : (int) $this->sizeBytes;
    }
    public function setSizeBytes(?int $bytes): self
    {
        $this->sizeBytes = $bytes === null ? null : (string) $bytes;
        return $this;
    }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException("Invalid download job status: {$status}");
        }
        $this->status = $status;
        return $this;
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    public function getProgress(): int { return $this->progress; }
    public function setProgress(int $progress): self
    {
        $this->progress = max(0, min(100, $progress));
        return $this;
    }

    public function getStatusMessage(): ?string { return $this->statusMessage; }
    public function setStatusMessage(?string $message): self { $this->statusMessage = $message; return $this; }

    public function getFilePath(): ?string { return $this->filePath; }
    public function setFilePath(?string $path): self { $this->filePath = $path; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
