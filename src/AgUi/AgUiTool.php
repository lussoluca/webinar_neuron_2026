<?php

declare(strict_types=1);

namespace App\AgUi;

/**
 * Represents a tool definition in the AG-UI protocol input.
 */
final readonly class AgUiTool
{
    public function __construct(
        public string $name,
        public string $description,
        /** @var array<string, mixed> */
        public array $parameters = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'] ?? '',
            description: $data['description'] ?? '',
            parameters: $data['parameters'] ?? [],
        );
    }
}
