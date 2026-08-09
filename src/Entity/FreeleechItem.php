<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FreeleechItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One MyAnonamouse torrent that is freeleech right now.
 *
 * This is an availability row, not a metadata row: the MAM strings it carries are
 * lookup keys for the Hardcover reverse lookup and availability facts for the grab
 * action, never display metadata. Once {@see $resolution} is `resolved` the linked
 * {@see Book} owns every field a card renders; unresolved rows fall back to the MAM
 * strings and {@see $thumbnailUrl}.
 *
 * Rows live and die with the freeleech sweep: unseen ids are deleted, so the table
 * always mirrors "what is free on MAM at this moment".
 */
#[ORM\Entity(repositoryClass: FreeleechItemRepository::class)]
#[ORM\Table(name: 'freeleech_items')]
#[ORM\UniqueConstraint(name: 'freeleech_items_mam_torrent_id_uniq', columns: ['mam_torrent_id'])]
#[ORM\Index(name: 'freeleech_items_resolution_idx', columns: ['resolution'])]
#[ORM\Index(name: 'freeleech_items_audiobook_idx', columns: ['audiobook'])]
#[ORM\Index(name: 'freeleech_items_free_idx', columns: ['free'])]
#[ORM\Index(name: 'freeleech_items_last_seen_at_idx', columns: ['last_seen_at'])]
class FreeleechItem
{
    public const RESOLUTION_PENDING   = 'pending';
    public const RESOLUTION_RESOLVED  = 'resolved';
    public const RESOLUTION_UNMATCHED = 'unmatched';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** MAM's torrent id — the natural key of the whole feature. */
    #[ORM\Column]
    private int $mamTorrentId;

    #[ORM\Column(length: 500)]
    private string $title;

    /**
     * Author names decoded out of MAM's `author_info` id→name map.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    private array $authors = [];

    /**
     * Comma-joined mirror of {@see $authors}, kept because Postgres cannot LOWER()/LIKE a
     * jsonb column and DQL has no CAST. Maintained by the setter; never set directly.
     */
    #[ORM\Column(type: Types::TEXT, options: ['default' => ''])]
    private string $authorsText = '';

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    private array $narrators = [];

    /** Comma-joined mirror of {@see $narrators}; see {@see $authorsText}. */
    #[ORM\Column(type: Types::TEXT, options: ['default' => ''])]
    private string $narratorsText = '';

    /** MAM `main_cat`: true = 13 (Audiobooks), false = 14 (E-Books). */
    #[ORM\Column]
    private bool $audiobook;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $catName = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $langCode = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $filetypes = null;

    /** Parsed out of MAM's human-readable size string ("1.2 GiB"). */
    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?string $sizeBytes = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $seeders = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $leechers = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $timesCompleted = 0;

    #[ORM\Column(options: ['default' => false])]
    private bool $vip = false;

    /** Free only for VIP tracker accounts; the "Include VIP freeleech" toggle keys off this. */
    #[ORM\Column(options: ['default' => false])]
    private bool $flVip = false;

    /**
     * MAM's `free`: free for every account. MAM stamps its freeleech picks with both this and
     * {@see $flVip}, so the regular shelf is this flag, never the absence of the VIP one.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $free = false;

    /** Free because this account spent a personal freeleech wedge on it. */
    #[ORM\Column(options: ['default' => false])]
    private bool $personalFreeleech = false;

    /** MAM download hash, kept for the future grab action. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $dlHash = null;

    /** Cover fallback for items the Hardcover reverse lookup cannot resolve. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $thumbnailUrl = null;

    /** MAM's upload date. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $addedAt = null;

    #[ORM\Column(length: 16, options: ['default' => self::RESOLUTION_PENDING])]
    private string $resolution = self::RESOLUTION_PENDING;

    /** The Hardcover-backed catalog row this item resolved to, if any. */
    #[ORM\ManyToOne(targetEntity: Book::class)]
    #[ORM\JoinColumn(name: 'book_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Book $book = null;

    #[ORM\Column]
    private \DateTimeImmutable $firstSeenAt;

    #[ORM\Column]
    private \DateTimeImmutable $lastSeenAt;

    public function __construct(int $mamTorrentId, string $title, bool $audiobook)
    {
        $now = new \DateTimeImmutable();
        $this->mamTorrentId = $mamTorrentId;
        $this->title = $title;
        $this->audiobook = $audiobook;
        $this->firstSeenAt = $now;
        $this->lastSeenAt = $now;
    }

    public function getId(): ?int { return $this->id; }
    public function getMamTorrentId(): int { return $this->mamTorrentId; }

    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): self { $this->title = $title; return $this; }

    /** @return list<string> */
    public function getAuthors(): array { return $this->authors; }
    /** @param list<string> $authors */
    public function setAuthors(array $authors): self
    {
        $this->authors = array_values($authors);
        $this->authorsText = implode(', ', $this->authors);
        return $this;
    }

    public function getAuthorsText(): string { return $this->authorsText; }

    /** @return list<string> */
    public function getNarrators(): array { return $this->narrators; }
    /** @param list<string> $narrators */
    public function setNarrators(array $narrators): self
    {
        $this->narrators = array_values($narrators);
        $this->narratorsText = implode(', ', $this->narrators);
        return $this;
    }

    public function getNarratorsText(): string { return $this->narratorsText; }

    public function isAudiobook(): bool { return $this->audiobook; }
    public function setAudiobook(bool $audiobook): self { $this->audiobook = $audiobook; return $this; }

    public function getCatName(): ?string { return $this->catName; }
    public function setCatName(?string $catName): self { $this->catName = $catName; return $this; }

    public function getLangCode(): ?string { return $this->langCode; }
    public function setLangCode(?string $langCode): self { $this->langCode = $langCode; return $this; }

    public function getFiletypes(): ?string { return $this->filetypes; }
    public function setFiletypes(?string $filetypes): self { $this->filetypes = $filetypes; return $this; }

    public function getSizeBytes(): ?int { return $this->sizeBytes === null ? null : (int) $this->sizeBytes; }
    public function setSizeBytes(?int $bytes): self { $this->sizeBytes = $bytes === null ? null : (string) $bytes; return $this; }

    public function getSeeders(): int { return $this->seeders; }
    public function setSeeders(int $seeders): self { $this->seeders = $seeders; return $this; }

    public function getLeechers(): int { return $this->leechers; }
    public function setLeechers(int $leechers): self { $this->leechers = $leechers; return $this; }

    public function getTimesCompleted(): int { return $this->timesCompleted; }
    public function setTimesCompleted(int $times): self { $this->timesCompleted = $times; return $this; }

    public function isVip(): bool { return $this->vip; }
    public function setVip(bool $vip): self { $this->vip = $vip; return $this; }

    public function isFlVip(): bool { return $this->flVip; }
    public function setFlVip(bool $flVip): self { $this->flVip = $flVip; return $this; }

    public function isFree(): bool { return $this->free; }
    public function setFree(bool $free): self { $this->free = $free; return $this; }

    public function isPersonalFreeleech(): bool { return $this->personalFreeleech; }
    public function setPersonalFreeleech(bool $personalFreeleech): self { $this->personalFreeleech = $personalFreeleech; return $this; }

    public function getDlHash(): ?string { return $this->dlHash; }
    public function setDlHash(?string $hash): self { $this->dlHash = $hash; return $this; }

    public function getThumbnailUrl(): ?string { return $this->thumbnailUrl; }
    public function setThumbnailUrl(?string $url): self { $this->thumbnailUrl = $url; return $this; }

    public function getAddedAt(): ?\DateTimeImmutable { return $this->addedAt; }
    public function setAddedAt(?\DateTimeImmutable $at): self { $this->addedAt = $at; return $this; }

    public function getResolution(): string { return $this->resolution; }
    public function setResolution(string $resolution): self { $this->resolution = $resolution; return $this; }

    public function isResolved(): bool { return $this->resolution === self::RESOLUTION_RESOLVED; }

    public function getBook(): ?Book { return $this->book; }
    public function setBook(?Book $book): self { $this->book = $book; return $this; }

    public function getFirstSeenAt(): \DateTimeImmutable { return $this->firstSeenAt; }
    public function getLastSeenAt(): \DateTimeImmutable { return $this->lastSeenAt; }
    public function setLastSeenAt(\DateTimeImmutable $when): self { $this->lastSeenAt = $when; return $this; }
}
