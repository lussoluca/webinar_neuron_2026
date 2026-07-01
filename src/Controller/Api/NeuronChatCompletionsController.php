<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Neuron\Chat\CacheAwareChatHistory;
use App\AgUi\RunAgentInput;
use App\Http\SseResponse;
use App\Neuron\Agent\HolidayPlanner;
use NeuronAI\Chat\Messages\Stream\Adapters\AGUIAdapter;
use NeuronAI\Chat\Messages\UserMessage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * AG-UI protocol compatible chat/completions endpoint powered by Neuron AI.
 */
#[Route(path: '/chat/completions', name: 'chat_completions', methods: ['POST'])]
final class NeuronChatCompletionsController extends AbstractController {

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly HolidayPlanner $agent,
    ) {}

    public function __invoke(Request $request): Response {
        $payload = json_decode(
            $request->getContent(),
            TRUE,
            512,
            \JSON_THROW_ON_ERROR
        );
        $input = RunAgentInput::fromArray($payload);

        $adapter = new AGUIAdapter($input->threadId);

        return new SseResponse(
            function() use ($input, $adapter): void {
                try {
                    $agent = $this->agent;
                    $agent->setChatHistory(
                        new CacheAwareChatHistory(
                            $this->cache, $input->threadId
                        )
                    );

                    $userContent = $this->extractUserMessage($input);

                    $stream = $agent->stream(new UserMessage($userContent));

                    foreach ($stream->events($adapter) as $line) {
                        echo $line;
                    }
                }
                catch (\Throwable $e) {
                    // Emit error as SSE since headers are already sent
                    $error = json_encode([
                        'type' => 'RUN_ERROR',
                        'message' => $e->getMessage(),
                        'code' => (string) $e->getCode(),
                    ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
                    echo "data: {$error}\n\n";
                }
            },
            $adapter->getHeaders(),
        );
    }

    /**
     * Extracts the content of the last user message from the AG-UI input.
     *
     * @throws \RuntimeException When no user message is present
     */
    private function extractUserMessage(RunAgentInput $input): string {
        foreach (array_reverse($input->messages) as $agUiMessage) {
            if ('user' === $agUiMessage->role) {
                return $agUiMessage->content ?? '';
            }
        }

        throw new \RuntimeException(
            'No user message found in the AG-UI input.'
        );
    }

}
