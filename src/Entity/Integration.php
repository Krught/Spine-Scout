<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IntegrationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: IntegrationRepository::class)]
#[ORM\Table(name: 'integrations')]
#[ORM\UniqueConstraint(name: 'integrations_kind_uniq', columns: ['kind'])]
#[ORM\HasLifecycleCallbacks]
class Integration
{
    public const KIND_GRIMMORY        = 'grimmory';
    public const KIND_HARDCOVER       = 'hardcover';
    public const KIND_OPENLIBRARY     = 'openlibrary';
    public const KIND_DIRECT_DOWNLOAD = 'direct_download';
    public const KIND_BEST_MATCH      = 'best_match';
    /** Prowlarr indexer aggregator — audiobook torrent search (baseUrl + API-key token). */
    public const KIND_PROWLARR        = 'prowlarr';
    /** qBittorrent download client — audiobook torrent fulfillment (baseUrl + basic auth). */
    public const KIND_QBITTORRENT     = 'qbittorrent';
    /** Singleton row holding app-wide ("General" tab) preferences in its options blob. */
    public const KIND_APP             = 'app';

    public const AUTH_API_KEY = 'api_key';
    public const AUTH_BASIC   = 'basic';
    public const AUTH_NONE    = 'none';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    private string $kind;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $baseUrl = null;

    #[ORM\Column(length: 20)]
    private string $authType = self::AUTH_BASIC;

    /**
     * Shape depends on $authType.
     * TODO: encrypt at rest once Symfony Secrets / a KMS-backed key is wired up.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $credentials = [];

    #[ORM\Column]
    private bool $enabled = false;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 15])]
    private int $syncIntervalMinutes = 15;

    /** @var array<int, array{id: string, name: string}> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    private array $discoveredLibraries = [];

    /** Empty array means "sync every library" (Komga's default when no library_id filter). @var array<int, string> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    private array $selectedLibraries = [];

    /** Per-kind free-form options (consumers own the schema). @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '{}'])]
    private array $options = [];

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastSyncAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $kind)
    {
        $this->kind = $kind;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getKind(): string { return $this->kind; }

    public function getName(): ?string { return $this->name; }
    public function setName(?string $name): self { $this->name = $name; return $this; }

    public function getBaseUrl(): ?string { return $this->baseUrl; }
    public function setBaseUrl(?string $baseUrl): self
    {
        $this->baseUrl = $baseUrl === null ? null : rtrim($baseUrl, '/');
        return $this;
    }

    public function getAuthType(): string { return $this->authType; }
    public function setAuthType(string $authType): self { $this->authType = $authType; return $this; }

    /** @return array<string, mixed> */
    public function getCredentials(): array { return $this->credentials; }
    /** @param array<string, mixed> $credentials */
    public function setCredentials(array $credentials): self { $this->credentials = $credentials; return $this; }

    public function isEnabled(): bool { return $this->enabled; }
    public function setEnabled(bool $enabled): self { $this->enabled = $enabled; return $this; }

    public function getSyncIntervalMinutes(): int { return $this->syncIntervalMinutes; }
    public function setSyncIntervalMinutes(int $minutes): self
    {
        $this->syncIntervalMinutes = max(1, $minutes);
        return $this;
    }

    /** @return array<int, array{id: string, name: string}> */
    public function getDiscoveredLibraries(): array { return $this->discoveredLibraries; }
    /** @param array<int, array{id: string, name: string}> $libraries */
    public function setDiscoveredLibraries(array $libraries): self
    {
        $this->discoveredLibraries = array_values($libraries);
        return $this;
    }

    /** @return array<int, string> */
    public function getSelectedLibraries(): array { return $this->selectedLibraries; }
    /** @param array<int, string> $ids */
    public function setSelectedLibraries(array $ids): self
    {
        $this->selectedLibraries = array_values(array_unique(array_filter($ids, static fn ($v) => is_string($v) && $v !== '')));
        return $this;
    }

    /** @return array<string, mixed> */
    public function getOptions(): array { return $this->options; }
    /** @param array<string, mixed> $options */
    public function setOptions(array $options): self { $this->options = $options; return $this; }

    /**
     * Priority-ordered edition-selection prefs; empty list = no preference on that axis.
     * Languages = ISO 639-3 (`language.code3`); countries = ISO 3166-1 alpha-2 (`country.code2`);
     * formats match Hardcover's `physical_format` string.
     *
     * @return array{languages: list<string>, formats: list<string>, countries: list<string>}
     */
    public function getHardcoverEditionPreferences(): array
    {
        $raw = is_array($this->options['hardcover_edition_sort'] ?? null) ? $this->options['hardcover_edition_sort'] : [];
        $clean = static function (mixed $v): array {
            if (!is_array($v)) {
                return [];
            }
            $out = [];
            foreach ($v as $item) {
                if (is_string($item) && $item !== '') {
                    $out[] = $item;
                }
            }
            return array_values(array_unique($out));
        };
        return [
            'languages' => $clean($raw['languages'] ?? null),
            'formats'   => $clean($raw['formats']   ?? null),
            'countries' => $clean($raw['countries'] ?? null),
        ];
    }

    /**
     * @param array{languages?: list<string>, formats?: list<string>, countries?: list<string>} $prefs
     */
    public function setHardcoverEditionPreferences(array $prefs): self
    {
        $options = $this->options;
        $options['hardcover_edition_sort'] = [
            'languages' => array_values(array_filter($prefs['languages'] ?? [], static fn ($v) => is_string($v) && $v !== '')),
            'formats'   => array_values(array_filter($prefs['formats']   ?? [], static fn ($v) => is_string($v) && $v !== '')),
            'countries' => array_values(array_filter($prefs['countries'] ?? [], static fn ($v) => is_string($v) && $v !== '')),
        ];
        $this->options = $options;
        return $this;
    }

    public function getBookPurgeThresholdDays(): int
    {
        $v = $this->options['book_purge_threshold_days'] ?? null;
        $n = is_int($v) ? $v : (is_numeric($v) ? (int) $v : 30);
        return max(1, min(365, $n));
    }

    public function setBookPurgeThresholdDays(int $days): self
    {
        $options = $this->options;
        $options['book_purge_threshold_days'] = max(1, min(365, $days));
        $this->options = $options;
        return $this;
    }

    public function getHardcoverGenreCount(): ?int
    {
        $v = $this->options['hardcover_genre_count'] ?? null;
        return is_int($v) ? $v : null;
    }

    public function setHardcoverGenreCount(?int $count): self
    {
        $options = $this->options;
        if ($count === null) {
            unset($options['hardcover_genre_count']);
        } else {
            $options['hardcover_genre_count'] = $count;
        }
        $this->options = $options;
        return $this;
    }

    /**
     * App-wide toggle (KIND_APP row): rewrite a downloaded ebook's embedded metadata
     * with Spine Scout's stored values before it lands in the library. Defaults to true
     * so a fresh install / missing row behaves as "enabled".
     */
    public function isOverwriteMetadataEnabled(): bool
    {
        return (bool) ($this->options['overwrite_metadata'] ?? true);
    }

    public function setOverwriteMetadataEnabled(bool $on): self
    {
        $options = $this->options;
        $options['overwrite_metadata'] = $on;
        $this->options = $options;
        return $this;
    }

    /**
     * App-wide toggle (KIND_APP row): auto-approve every new book request,
     * skipping the admin approval queue. Defaults to false so existing installs
     * keep the manual-approval behavior.
     */
    public function isAutoApproveRequestsEnabled(): bool
    {
        return (bool) ($this->options['auto_approve_requests'] ?? false);
    }

    public function setAutoApproveRequestsEnabled(bool $on): self
    {
        $options = $this->options;
        $options['auto_approve_requests'] = $on;
        $this->options = $options;
        return $this;
    }

    public function getLastSyncAt(): ?\DateTimeImmutable { return $this->lastSyncAt; }
    public function setLastSyncAt(?\DateTimeImmutable $when): self { $this->lastSyncAt = $when; return $this; }

    public function getLastError(): ?string { return $this->lastError; }
    public function setLastError(?string $err): self { $this->lastError = $err; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function hasCredentials(): bool
    {
        return $this->credentials !== [] && array_filter($this->credentials, static fn ($v) => $v !== null && $v !== '') !== [];
    }
}
