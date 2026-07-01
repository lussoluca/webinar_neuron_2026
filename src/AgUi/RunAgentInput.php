<?php

declare(strict_types=1);

namespace App\AgUi;

/**
 * Represents the input payload for an AG-UI agent run.
 *
 * This maps to the RunAgentInput type from the AG-UI protocol specification.
 *
 * @see https://docs.ag-ui.com/concepts/architecture
 */
final readonly class RunAgentInput
{
    /**
     * @param AgUiMessage[] $messages
     * @param AgUiTool[]    $tools
     * @param array<int, mixed> $context
     * @param array<string, mixed> $forwardedProps
     * @param array<string, mixed> $state
     */
    public function __construct(
        public string $threadId,
        public string $runId,
        public array $messages = [],
        public array $tools = [],
        public array $context = [],
        public array $forwardedProps = [],
        public array $state = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $messages = array_map(
            static fn (array $msg) => AgUiMessage::fromArray($msg),
            $data['messages'] ?? [],
        );

        $tools = array_map(
            static fn (array $tool) => AgUiTool::fromArray($tool),
            $data['tools'] ?? [],
        );

        return new self(
            threadId: $data['threadId'] ?? '',
            runId: $data['runId'] ?? '',
            messages: $messages,
            tools: $tools,
            context: $data['context'] ?? [],
            forwardedProps: $data['forwardedProps'] ?? [],
            state: $data['state'] ?? [],
        );
    }
}
