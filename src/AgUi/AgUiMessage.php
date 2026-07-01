<?php

declare(strict_types=1);

namespace App\AgUi;

/**
 * Represents a message in the AG-UI protocol input.
 */
final readonly class AgUiMessage
{
    public function __construct(
        public string $id,
        public string $role,
        public ?string $content = null,
        /** @var array<int, array<string, mixed>>|null */
        public ?array $toolCalls = null,
        public ?string $toolCallId = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'] ?? '',
            role: $data['role'] ?? 'user',
            content: $data['content'] ?? null,
            toolCalls: $data['toolCalls'] ?? null,
            toolCallId: $data['toolCallId'] ?? null,
        );
    }
}
