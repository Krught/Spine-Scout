<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FulfillmentEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A single line in the fulfillment activity log: a human-readable record of what
 * the request→search→download loop did (search started, match picked, link
 * failed, file delivered, request marked available, …). Written by FulfillmentLog
 * and surfaced live on the Development page so an operator can watch the pipeline
 * work. Append-only; not part of any domain decision.
 */
#[ORM\Entity(repositoryClass: FulfillmentEventRepository::class)]
#[ORM\Table(name: 'fulfillment_events')]
#[ORM\Index(name: 'fulfillment_events_created_idx', columns: ['created_at'])]
class FulfillmentEvent
{
    public const LEVEL_INFO  = 'info';
    public const LEVEL_WARN  = 'warn';
    public const LEVEL_ERROR = 'error';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 8)]
    private string $level;

    #[ORM\Column(type: Types::TEXT)]
    private string $message;

    /** Short context label — typically the book title the event concerns. */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $subject = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $level, string $message, ?string $subject = null)
    {
        $this->level = $level;
        $this->message = $message;
        $this->subject = $subject;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getLevel(): string { return $this->level; }
    public function getMessage(): string { return $this->message; }
    public function getSubject(): ?string { return $this->subject; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
