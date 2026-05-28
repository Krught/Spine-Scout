<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AuthorRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AuthorRepository::class)]
#[ORM\Table(name: 'authors')]
#[ORM\UniqueConstraint(name: 'authors_source_slug_uniq', columns: ['source', 'slug'])]
#[ORM\Index(name: 'authors_source_idx', columns: ['source'])]
#[ORM\HasLifecycleCallbacks]
class Author
{
    public const SOURCE_HARDCOVER = 'hardcover';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32)]
    private string $source;

    #[ORM\Column(length: 191)]
    private string $slug;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $bio = null;

    #[ORM\Column(length: 1000, nullable: true)]
    private ?string $imageUrl = null;

    #[ORM\Column(length: 1000, nullable: true)]
    private ?string $externalUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $location = null;

    #[ORM\Column(nullable: true)]
    private ?int $bornYear = null;

    #[ORM\Column(nullable: true)]
    private ?int $deathYear = null;

    #[ORM\Column(nullable: true)]
    private ?int $booksCount = null;

    #[ORM\Column(nullable: true)]
    private ?int $usersCount = null;

    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    private array $topBooks = [];

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $metadataFetchedAt = null;

    /** Rank within the most recent "popular authors" shelf (1 = top). NULL when not on the current shelf. */
    #[ORM\Column(nullable: true)]
    private ?int $popularRank = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $popularFetchedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $source, string $slug, string $name)
    {
        $now = new \DateTimeImmutable();
        $this->source = $source;
        $this->slug = $slug;
        $this->name = $name;
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
    public function getSlug(): string { return $this->slug; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function getBio(): ?string { return $this->bio; }
    public function setBio(?string $bio): self { $this->bio = $bio; return $this; }

    public function getImageUrl(): ?string { return $this->imageUrl; }
    public function setImageUrl(?string $url): self { $this->imageUrl = $url; return $this; }

    public function getExternalUrl(): ?string { return $this->externalUrl; }
    public function setExternalUrl(?string $url): self { $this->externalUrl = $url; return $this; }

    public function getLocation(): ?string { return $this->location; }
    public function setLocation(?string $location): self { $this->location = $location; return $this; }

    public function getBornYear(): ?int { return $this->bornYear; }
    public function setBornYear(?int $year): self { $this->bornYear = $year; return $this; }

    public function getDeathYear(): ?int { return $this->deathYear; }
    public function setDeathYear(?int $year): self { $this->deathYear = $year; return $this; }

    public function getBooksCount(): ?int { return $this->booksCount; }
    public function setBooksCount(?int $count): self { $this->booksCount = $count; return $this; }

    public function getUsersCount(): ?int { return $this->usersCount; }
    public function setUsersCount(?int $count): self { $this->usersCount = $count; return $this; }

    public function getTopBooks(): array { return $this->topBooks; }

    public function setTopBooks(array $books): self { $this->topBooks = array_values($books); return $this; }

    public function getMetadataFetchedAt(): ?\DateTimeImmutable { return $this->metadataFetchedAt; }
    public function setMetadataFetchedAt(?\DateTimeImmutable $when): self { $this->metadataFetchedAt = $when; return $this; }

    public function getPopularRank(): ?int { return $this->popularRank; }
    public function setPopularRank(?int $rank): self { $this->popularRank = $rank; return $this; }

    public function getPopularFetchedAt(): ?\DateTimeImmutable { return $this->popularFetchedAt; }
    public function setPopularFetchedAt(?\DateTimeImmutable $when): self { $this->popularFetchedAt = $when; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
