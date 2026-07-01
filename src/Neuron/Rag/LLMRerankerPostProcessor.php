<?php

declare(strict_types=1);

namespace App\Neuron\Rag;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\RAG\PostProcessor\PostProcessorInterface;

/**
 * Reranks retrieved documents with an LLM instead of a dedicated rerank API.
 *
 * The candidate documents (already narrowed by vector similarity) are handed to
 * a fluent agent that returns a validated {@see Ranking} — their indexes ordered
 * from most to least relevant to the question. The top N are kept. The agent's
 * structured output does the JSON parsing, validation and inference retry
 * built-in; if it still fails, the original similarity order is preserved so
 * retrieval never hard-fails on a flaky rerank.
 */
final readonly class LLMRerankerPostProcessor implements PostProcessorInterface
{
    public function __construct(
        private AIProviderInterface $provider,
        private int $topN = 3,
    ) {
    }

    public function process(Message $question, array $documents): array
    {
        if (\count($documents) <= 1) {
            return $documents;
        }

        $candidates = [];
        foreach (array_values($documents) as $index => $document) {
            $candidates[] = "[{$index}] " . $this->oneLine($document->getContent());
        }
        $candidateList = implode("\n", $candidates);

        $query = (string) $question->getContent();

        $prompt = <<<PROMPT
            Query:
            {$query}

            Candidates:
            {$candidateList}
            PROMPT;

        try {
            $agent = Agent::make();
            $agent->setAiProvider($this->provider);
            $agent->setInstructions(
                'You are a search result reranker. Given a user query and a '
                . 'numbered list of candidate documents, return the indexes '
                . 'ordered from most to least relevant to the query.',
            );

            /** @var Ranking $ranking */
            $ranking = $agent->structured(new UserMessage($prompt), Ranking::class);
            $order = $this->sanitizeOrder($ranking->list, \count($documents));
        } catch (\Throwable) {
            $order = [];
        }

        if ([] === $order) {
            // Rerank failed or returned nothing usable: keep similarity order.
            return \array_slice(array_values($documents), 0, $this->topN);
        }

        $indexed = array_values($documents);
        $reranked = [];
        foreach ($order as $position => $docIndex) {
            $document = $indexed[$docIndex];
            // Surface the rerank position as the new score (1.0 .. ~0).
            $document->setScore(1.0 - ($position / max(1, \count($order))));
            $reranked[] = $document;
        }

        return \array_slice($reranked, 0, $this->topN);
    }

    /**
     * Drop out-of-range and duplicate indexes from the model's ranking. Type
     * validation is already handled by the structured output layer.
     *
     * @param list<int> $list
     *
     * @return list<int>
     */
    private function sanitizeOrder(array $list, int $count): array
    {
        $seen = [];
        $order = [];
        foreach ($list as $value) {
            if ($value < 0 || $value >= $count || isset($seen[$value])) {
                continue;
            }
            $seen[$value] = true;
            $order[] = $value;
        }

        return $order;
    }

    private function oneLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}
