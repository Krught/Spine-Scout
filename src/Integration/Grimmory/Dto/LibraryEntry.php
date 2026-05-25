<?php

declare(strict_types=1);

namespace App\Integration\Grimmory\Dto;

/**
 * One library exposed by `GET /api/v1/libraries`. The settings UI offers
 * these as a multi-select; an empty selection means "sync every library".
 */
final readonly class LibraryEntry
{
    public function __construct(
        public string $id,
        public string $name,
    ) {
    }

    /** @return array{id: string, name: string} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name];
    }
}
