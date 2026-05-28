<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BookRequestRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BookRequestRepository::class)]
#[ORM\Table(name: 'book_requests')]
#[ORM\Index(name: 'book_requests_status_idx', columns: ['status'])]
#[ORM\Index(name: 'book_requests_requested_by_idx', columns: ['requested_by_id'])]
#[ORM\Index(name: 'book_requests_book_idx', columns: ['book_id'])]
#[ORM\HasLifecycleCallbacks]
class BookRequest
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_APPROVED  = 'approved';
    public const STATUS_REJECTED  = 'rejected';
    public const STATUS_AVAILABLE = 'available';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_AVAILABLE,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Book::class)]
    #[ORM\JoinColumn(name: 'book_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Book $book;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'requested_by_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $requestedBy;

    #[ORM\Column(length: 16, options: ['default' => self::STATUS_PENDING])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $requestedBy, Book $book)
    {
        $now = new \DateTimeImmutable();
        $this->requestedBy = $requestedBy;
        $this->book = $book;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getBook(): Book { return $this->book; }
    public function setBook(Book $book): self { $this->book = $book; return $this; }

    public function getRequestedBy(): User { return $this->requestedBy; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException("Invalid status: {$status}");
        }
        $this->status = $status;
        return $this;
    }

    public function getStatusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING   => 'Pending',
            self::STATUS_APPROVED  => 'Approved',
            self::STATUS_REJECTED  => 'Rejected',
            self::STATUS_AVAILABLE => 'Available',
            default                => ucfirst($this->status),
        };
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
