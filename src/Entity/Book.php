<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BookRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BookRepository::class)]
#[ORM\Table(name: 'books')]
#[ORM\UniqueConstraint(name: 'books_source_external_uniq', columns: ['source', 'external_id'])]
#[ORM\Index(name: 'books_source_idx', columns: ['source'])]
#[ORM\Index(name: 'books_removed_at_idx', columns: ['removed_at'])]
#[ORM\Index(name: 'books_isbn_idx', columns: ['isbn'])]
#[ORM\HasLifecycleCallbacks]
class Book
{
    public const SOURCE_GRIMMORY    = 'grimmory';
    public const SOURCE_HARDCOVER   = 'hardcover';
    public const SOURCE_OPENLIBRARY = 'openlibrary';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32)]
    private string $source;

    #[ORM\Column(length: 191)]
    private string $externalId;

    #[ORM\Column(length: 500)]
    private string $title;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $author = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $series = null;

    /** Free-form (e.g. "3", "3.5") to tolerate OPDS variants. */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $seriesIndex = null;

    #[ORM\Column(length: 1000, nullable: true)]
    private ?string $externalUrl = null;

    /** Remote cover URL (Hardcover/OpenLibrary). Library books use the Komga proxy instead. */
    #[ORM\Column(length: 1000, nullable: true)]
    private ?string $coverUrl = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $komgaLibraryId = null;

    /** Normalized (digits only, trailing 'X' for ISBN-10). Canonical "have" key for trending matches. */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $isbn = null;

    /**
     * Every edition's ISBN, normalized. Populated for metadata-only entries (Hardcover/OL
     * trending) so we can flag "downloaded" even when the owned copy is a different edition
     * than the one whose ISBN we put in the singular `isbn` column. Empty for Grimmory rows.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    private array $isbns = [];

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $addedAt = null;

    /** Komga's `lastModified`, used in place of a content hash to detect refresh need. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastModifiedAt = null;

    /** Distinguishes library-owned books from trending/wishlist entries that only exist as metadata. */
    #[ORM\Column]
    private bool $downloaded = true;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $publisher = null;

    /** Free-form: "2024", "2024-05-21", "May 2024" all round-trip across sources. */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $publishedDate = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $language = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    private array $genres = [];

    #[ORM\Column(nullable: true)]
    private ?int $seriesTotal = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $metadataFetchedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $firstSeenAt;

    #[ORM\Column]
    private \DateTimeImmutable $lastSeenAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $removedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $source, string $externalId, string $title)
    {
        $now = new \DateTimeImmutable();
        $this->source = $source;
        $this->externalId = $externalId;
        $this->title = $title;
        $this->firstSeenAt = $now;
        $this->lastSeenAt = $now;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getSource(): string { return $this->source; }
    public function getExternalId(): string { return $this->externalId; }

    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): self { $this->title = $title; return $this; }

    public function getAuthor(): ?string { return $this->author; }
    public function setAuthor(?string $author): self { $this->author = $author; return $this; }

    public function getSeries(): ?string { return $this->series; }
    public function setSeries(?string $series): self { $this->series = $series; return $this; }

    public function getSeriesIndex(): ?string { return $this->seriesIndex; }
    public function setSeriesIndex(?string $index): self { $this->seriesIndex = $index; return $this; }

    public function getExternalUrl(): ?string { return $this->externalUrl; }
    public function setExternalUrl(?string $url): self { $this->externalUrl = $url; return $this; }

    public function getCoverUrl(): ?string { return $this->coverUrl; }
    public function setCoverUrl(?string $url): self { $this->coverUrl = $url; return $this; }

    public function getKomgaLibraryId(): ?string { return $this->komgaLibraryId; }
    public function setKomgaLibraryId(?string $id): self { $this->komgaLibraryId = $id; return $this; }

    public function getIsbn(): ?string { return $this->isbn; }
    public function setIsbn(?string $isbn): self { $this->isbn = $isbn; return $this; }

    /** @return list<string> */
    public function getIsbns(): array { return $this->isbns; }
    /** @param list<string> $isbns */
    public function setIsbns(array $isbns): self { $this->isbns = array_values(array_unique($isbns)); return $this; }

    public function getAddedAt(): ?\DateTimeImmutable { return $this->addedAt; }
    public function setAddedAt(?\DateTimeImmutable $at): self { $this->addedAt = $at; return $this; }

    public function getLastModifiedAt(): ?\DateTimeImmutable { return $this->lastModifiedAt; }
    public function setLastModifiedAt(?\DateTimeImmutable $at): self { $this->lastModifiedAt = $at; return $this; }

    public function isDownloaded(): bool { return $this->downloaded; }
    public function setDownloaded(bool $downloaded): self { $this->downloaded = $downloaded; return $this; }

    public function getPublisher(): ?string { return $this->publisher; }
    public function setPublisher(?string $publisher): self { $this->publisher = $publisher; return $this; }

    public function getPublishedDate(): ?string { return $this->publishedDate; }
    public function setPublishedDate(?string $date): self { $this->publishedDate = $date; return $this; }

    public function getLanguage(): ?string { return $this->language; }
    public function setLanguage(?string $language): self { $this->language = $language; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): self { $this->description = $description; return $this; }

    /** @return list<string> */
    public function getGenres(): array { return $this->genres; }
    /** @param list<string> $genres */
    public function setGenres(array $genres): self { $this->genres = array_values($genres); return $this; }

    public function getSeriesTotal(): ?int { return $this->seriesTotal; }
    public function setSeriesTotal(?int $total): self { $this->seriesTotal = $total; return $this; }

    public function getMetadataFetchedAt(): ?\DateTimeImmutable { return $this->metadataFetchedAt; }
    public function setMetadataFetchedAt(?\DateTimeImmutable $when): self { $this->metadataFetchedAt = $when; return $this; }

    public function getFirstSeenAt(): \DateTimeImmutable { return $this->firstSeenAt; }
    public function getLastSeenAt(): \DateTimeImmutable { return $this->lastSeenAt; }
    public function setLastSeenAt(\DateTimeImmutable $when): self { $this->lastSeenAt = $when; return $this; }

    public function getRemovedAt(): ?\DateTimeImmutable { return $this->removedAt; }
    public function setRemovedAt(?\DateTimeImmutable $when): self { $this->removedAt = $when; return $this; }
    public function isRemoved(): bool { return $this->removedAt !== null; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
