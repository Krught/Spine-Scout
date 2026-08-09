<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\UniqueConstraint(name: 'users_username_uniq', columns: ['username'])]
#[ORM\HasLifecycleCallbacks]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const ROLE_USER  = 'ROLE_USER';
    public const ROLE_ADMIN = 'ROLE_ADMIN';

    /** Capability roles. ROLE_ADMIN implies them all via role_hierarchy (see security.yaml). */
    public const ROLE_MANAGE_SETTINGS    = 'ROLE_MANAGE_SETTINGS';
    public const ROLE_MANAGE_USERS       = 'ROLE_MANAGE_USERS';
    public const ROLE_INTERACTIVE_SEARCH = 'ROLE_INTERACTIVE_SEARCH';
    public const ROLE_REIMPORT           = 'ROLE_REIMPORT';
    public const ROLE_VIEW_FREELEECH     = 'ROLE_VIEW_FREELEECH';

    public const USERNAME_MIN = 3;
    public const USERNAME_MAX = 60;
    public const USERNAME_PATTERN = '/^[a-z0-9][a-z0-9._-]{1,58}[a-z0-9]$/';

    public const PASSWORD_MIN = 8;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 60)]
    private string $username;

    #[ORM\Column(length: 255)]
    private string $password;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $roles = [];

    /** The protected first user: never deletable, always holds every capability. */
    #[ORM\Column]
    private bool $isMaster = false;

    /** When true, this user's book requests auto-approve regardless of the global toggle. */
    #[ORM\Column]
    private bool $autoApproveRequests = false;

    /**
     * Per-user Discover customization: `order` is the section keys in the user's
     * preferred order, `hidden` the keys they switched off. Null means defaults.
     *
     * @var array{order?: list<string>, hidden?: list<string>}|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $homeSections = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $username)
    {
        $now = new \DateTimeImmutable();
        $this->username = self::normalizeUsername($username);
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public static function normalizeUsername(string $username): string
    {
        return mb_strtolower(trim($username));
    }

    public function getId(): ?int { return $this->id; }

    public function getUsername(): string { return $this->username; }
    public function setUsername(string $username): self { $this->username = self::normalizeUsername($username); return $this; }

    public function getUserIdentifier(): string { return $this->username; }

    public function getPassword(): string { return $this->password; }
    public function setPassword(string $hashed): self { $this->password = $hashed; return $this; }

    /** @return list<string> */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = self::ROLE_USER;
        return array_values(array_unique($roles));
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): self { $this->roles = array_values(array_unique($roles)); return $this; }

    public function isAdmin(): bool
    {
        return in_array(self::ROLE_ADMIN, $this->getRoles(), true);
    }

    public function isMaster(): bool { return $this->isMaster; }
    public function setMaster(bool $master): self { $this->isMaster = $master; return $this; }

    public function isAutoApproveRequests(): bool { return $this->autoApproveRequests; }
    public function setAutoApproveRequests(bool $on): self { $this->autoApproveRequests = $on; return $this; }

    /** Raw-role capability check for UI/templates (ROLE_ADMIN, and thus master, implies true). */
    public function canManageSettings(): bool
    {
        return $this->isAdmin() || in_array(self::ROLE_MANAGE_SETTINGS, $this->roles, true);
    }

    public function canManageUsers(): bool
    {
        return $this->isAdmin() || in_array(self::ROLE_MANAGE_USERS, $this->roles, true);
    }

    public function canUseInteractiveSearch(): bool
    {
        return $this->isAdmin() || in_array(self::ROLE_INTERACTIVE_SEARCH, $this->roles, true);
    }

    public function canReimport(): bool
    {
        return $this->isAdmin() || in_array(self::ROLE_REIMPORT, $this->roles, true);
    }

    public function canViewFreeleech(): bool
    {
        return $this->isAdmin() || in_array(self::ROLE_VIEW_FREELEECH, $this->roles, true);
    }

    /** @return list<string> */
    public function getHomeSectionsOrder(): array
    {
        return self::stringList($this->homeSections['order'] ?? null);
    }

    /** @return list<string> */
    public function getHiddenHomeSections(): array
    {
        return self::stringList($this->homeSections['hidden'] ?? null);
    }

    /** @param array{order?: list<string>, hidden?: list<string>}|null $prefs */
    public function setHomeSections(?array $prefs): self
    {
        if ($prefs === null) {
            $this->homeSections = null;

            return $this;
        }

        $this->homeSections = [
            'order'  => self::stringList($prefs['order'] ?? null),
            'hidden' => self::stringList($prefs['hidden'] ?? null),
        ];

        return $this;
    }

    /** @return list<string> */
    private static function stringList(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $value) {
            if (is_string($value) && $value !== '' && !in_array($value, $out, true)) {
                $out[] = $value;
            }
        }

        return $out;
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function eraseCredentials(): void
    {
    }
}
