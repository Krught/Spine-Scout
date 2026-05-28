<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BookSectionEntryRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Link row that places a `Book` into a per-source editorial shelf (e.g. Hardcover trending,
 * new releases, upcoming, staff picks, browse). The refresh handlers wipe-and-rewrite all
 * entries for a `(source, section)` pair on each sync, so `rank` is the upstream order at
 * `fetched_at` and nothing else.
 */
#[ORM\Entity(repositoryClass: BookSectionEntryRepository::class)]
#[ORM\Table(name: 'book_section_entries')]
#[ORM\UniqueConstraint(name: 'book_section_entries_unique', columns: ['source', 'section', 'book_id'])]
#[ORM\Index(name: 'book_section_entries_rank_idx', columns: ['source', 'section', 'rank'])]
#[ORM\Index(name: 'book_section_entries_book_idx', columns: ['book_id'])]
class BookSectionEntry
{
    public const SECTION_TRENDING     = 'trending';
    public const SECTION_NEW_RELEASES = 'new_releases';
    public const SECTION_UPCOMING     = 'upcoming';
    public const SECTION_STAFF_PICKS  = 'staff_picks';
    public const SECTION_BROWSE       = 'browse';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32)]
    private string $source;

    #[ORM\Column(length: 32)]
    private string $section;

    #[ORM\ManyToOne(targetEntity: Book::class)]
    #[ORM\JoinColumn(name: 'book_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Book $book;

    #[ORM\Column]
    private int $rank;

    #[ORM\Column]
    private \DateTimeImmutable $fetchedAt;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $source, string $section, Book $book, int $rank, \DateTimeImmutable $fetchedAt)
    {
        $this->source = $source;
        $this->section = $section;
        $this->book = $book;
        $this->rank = $rank;
        $this->fetchedAt = $fetchedAt;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getSource(): string { return $this->source; }
    public function getSection(): string { return $this->section; }
    public function getBook(): Book { return $this->book; }
    public function getRank(): int { return $this->rank; }
    public function getFetchedAt(): \DateTimeImmutable { return $this->fetchedAt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
