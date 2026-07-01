<?php

declare(strict_types=1);

namespace App\Neuron\Rag;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\RAG\PostProcessor\PostProcessorInterface;

/**
 * Reranks retrieved documents with an LLM instead of a dedicated rerank API.
 *
 * The candidate documents (already narrowed by vector similarity) are handed to
 * the model, which returns their indexes ordered from most to least relevant to
 * the question. The top N are kept. If the model output cannot be parsed, the
 * original similarity order is preserved so retrieval never hard-fails on a
 * flaky rerank.
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
            You are a search result reranker. Given a user query and a numbered
            list of candidate documents, return the indexes ordered from most to
            least relevant to the query.

            Respond with ONLY a JSON array of integers, most relevant first, no
            prose. Example: [2,0,1]

            Query:
            {$query}

            Candidates:
            {$candidateList}
            PROMPT;

        try {
            $response = $this->provider->chat(new UserMessage($prompt));
            $order = $this->parseOrder((string) $response->getContent(), \count($documents));
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
     * Parse a JSON array of integer indexes, dropping out-of-range / duplicate
     * entries. Tolerates surrounding text by extracting the first [...] block.
     *
     * @return list<int>
     */
    private function parseOrder(string $content, int $count): array
    {
        if (1 !== preg_match('/\[[^\]]*\]/s', $content, $matches)) {
            return [];
        }

        $decoded = json_decode($matches[0], true);
        if (!\is_array($decoded)) {
            return [];
        }

        $seen = [];
        $order = [];
        foreach ($decoded as $value) {
            if (!\is_int($value) || $value < 0 || $value >= $count || isset($seen[$value])) {
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
