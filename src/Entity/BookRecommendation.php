<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BookRecommendationRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Link row placing a recommended `Book` under a seed `Book` ("more like this").
 * Computed from Hardcover list co-occurrence; the service wipe-and-rewrites all rows
 * for a seed on each refresh, so `rank` is the co-occurrence order at `computed_at`
 * (rank 1 = strongest match) and nothing else. Mirrors {@see BookSectionEntry}.
 */
#[ORM\Entity(repositoryClass: BookRecommendationRepository::class)]
#[ORM\Table(name: 'book_recommendations')]
#[ORM\UniqueConstraint(name: 'book_recommendations_unique', columns: ['seed_book_id', 'book_id'])]
#[ORM\Index(name: 'book_recommendations_rank_idx', columns: ['seed_book_id', 'rank'])]
#[ORM\Index(name: 'book_recommendations_book_idx', columns: ['book_id'])]
class BookRecommendation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Book::class)]
    #[ORM\JoinColumn(name: 'seed_book_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Book $seedBook;

    #[ORM\ManyToOne(targetEntity: Book::class)]
    #[ORM\JoinColumn(name: 'book_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Book $book;

    #[ORM\Column]
    private int $rank;

    #[ORM\Column]
    private \DateTimeImmutable $computedAt;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Book $seedBook, Book $book, int $rank, \DateTimeImmutable $computedAt)
    {
        $this->seedBook = $seedBook;
        $this->book = $book;
        $this->rank = $rank;
        $this->computedAt = $computedAt;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getSeedBook(): Book { return $this->seedBook; }
    public function getBook(): Book { return $this->book; }
    public function getRank(): int { return $this->rank; }
    public function getComputedAt(): \DateTimeImmutable { return $this->computedAt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
