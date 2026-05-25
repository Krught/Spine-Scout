<?php

declare(strict_types=1);

namespace App\Integration\Hardcover\Dto;

final readonly class PopularAuthor
{
    public function __construct(
        public string $name,
        public ?string $slug = null,
        public ?string $imageUrl = null,
        public ?string $externalUrl = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'imageUrl' => $this->imageUrl,
            'externalUrl' => $this->externalUrl,
        ];
    }
}
