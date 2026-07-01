<?php

declare(strict_types=1);

namespace App\Neuron\Tool;

use App\Neuron\Rag\LLMRerankerPostProcessor;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\Embeddings\OpenAIEmbeddingsProvider;
use NeuronAI\RAG\VectorStore\FileVectorStore;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

/**
 * Finds Italian restaurants from a small RAG knowledge base and renders the
 * result as a fixed A2UI surface.
 *
 * Pipeline:
 *   1. A {@see FileVectorStore} is seeded once from data/restaurants.json,
 *      each restaurant embedded via the OpenAI embeddings provider.
 *   2. The query is embedded and the store returns the nearest candidates by
 *      cosine similarity.
 *   3. {@see LLMRerankerPostProcessor} reranks those candidates with the LLM.
 *   4. The top results are wrapped into a fixed, programmatically-defined A2UI
 *      card layout — the model never chooses the widget shape.
 */
final class RestaurantTool extends Tool
{
    private const string VECTOR_STORE_NAME = 'restaurants';
    private const string SOURCE_TYPE = 'seed';
    private const string SOURCE_NAME = 'restaurants.json';

    private readonly EmbeddingsProviderInterface $embeddingsProvider;
    private readonly AIProviderInterface $rerankProvider;
    private readonly string $seedFile;
    private readonly string $vectorStoreDir;
    private ?FileVectorStore $vectorStore = null;

    public function __construct(
        ?EmbeddingsProviderInterface $embeddingsProvider = null,
        ?AIProviderInterface $rerankProvider = null,
        private readonly int $topN = 3,
        ?string $seedFile = null,
        ?string $vectorStoreDir = null,
    ) {
        parent::__construct(
            'find_restaurants',
            'Find Italian restaurants matching a description (city, cuisine, '
            . 'dish, budget, occasion). Returns a ready-to-render restaurant '
            . 'card UI. Use this whenever the user is looking for somewhere to eat.',
        );

        $this->embeddingsProvider = $embeddingsProvider ?? new OpenAIEmbeddingsProvider(
            key: self::env('OPENAI_API_KEY'),
            model: 'text-embedding-3-small',
        );
        $this->rerankProvider = $rerankProvider ?? new Anthropic(
            key: self::env('ANTHROPIC_API_KEY'),
            model: 'claude-sonnet-4-5-20250929',
        );

        $projectDir = \dirname(__DIR__, 3);
        $this->seedFile = $seedFile ?? $projectDir . '/data/restaurants.json';
        $this->vectorStoreDir = $vectorStoreDir ?? $projectDir . '/var/rag';
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'query',
                type: PropertyType::STRING,
                description: 'What the user is looking for, e.g. "pizza economica a '
                    . 'Napoli" or "ristorante stellato per anniversario".',
                required: true,
            ),
            new ToolProperty(
                name: 'city',
                type: PropertyType::STRING,
                description: 'Optional city filter. When set, only restaurants in '
                    . 'that Italian city are returned, e.g. "Roma", "Napoli".',
                required: false,
            ),
        ];
    }

    public function __invoke(string $query, ?string $city = null): string
    {
        try {
            $store = $this->vectorStore();

            $queryEmbedding = $this->embeddingsProvider->embedText($query);
            $candidates = $store->similaritySearch($queryEmbedding);
            $candidates = \is_array($candidates) ? $candidates : iterator_to_array($candidates);

            if (null !== $city && '' !== trim($city)) {
                $candidates = $this->filterByCity($candidates, $city);
            }

            if ([] === $candidates) {
                return $this->renderEmpty($query, $city);
            }

            $reranker = new LLMRerankerPostProcessor($this->rerankProvider, $this->topN);
            $restaurants = $reranker->process(new UserMessage($query), $candidates);

            return $this->renderRestaurants($restaurants);
        } catch (\Throwable $e) {
            return json_encode([
                'error' => 'Failed to search restaurants: ' . $e->getMessage(),
            ], \JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * Keep only candidates whose city metadata matches the requested city
     * (case-insensitive, accent-tolerant).
     *
     * @param Document[] $candidates
     *
     * @return Document[]
     */
    private function filterByCity(array $candidates, string $city): array
    {
        $target = $this->normalizeCity($city);

        return array_values(array_filter(
            $candidates,
            fn (Document $doc): bool => $this->normalizeCity((string) ($doc->metadata['city'] ?? '')) === $target,
        ));
    }

    private function normalizeCity(string $city): string
    {
        return trim(mb_strtolower($city));
    }

    /**
     * Lazily build the vector store, seeding it from the JSON file on first use.
     */
    private function vectorStore(): FileVectorStore
    {
        if (null !== $this->vectorStore) {
            return $this->vectorStore;
        }

        $store = new FileVectorStore(
            // Retrieve a wide candidate set so the optional city filter and the
            // LLM reranker have enough material to work with.
            directory: $this->vectorStoreDir,
            topK: max($this->topN * 6, 24),
            name: self::VECTOR_STORE_NAME,
        );

        $storeFile = $this->vectorStoreDir . '/' . self::VECTOR_STORE_NAME . '.store';
        if (!is_file($storeFile) || 0 === filesize($storeFile)) {
            $store->addDocuments(
                $this->embeddingsProvider->embedDocuments($this->seedDocuments()),
            );
        }

        return $this->vectorStore = $store;
    }

    /**
     * Build one embeddable Document per restaurant, keeping the structured
     * fields in metadata so the card can be rendered without re-parsing text.
     *
     * @return Document[]
     */
    private function seedDocuments(): array
    {
        $raw = file_get_contents($this->seedFile);
        if (false === $raw) {
            throw new \RuntimeException("Cannot read restaurant seed file: {$this->seedFile}");
        }

        /** @var array<int, array<string, mixed>> $rows */
        $rows = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);

        $documents = [];
        foreach ($rows as $row) {
            // Embed the natural-language profile so similarity matches intent.
            $content = sprintf(
                '%s — %s a %s. %s Cuisine: %s. Signature dish: %s. Price range: %s.',
                $row['name'],
                $row['cuisine'],
                $row['city'],
                $row['description'],
                $row['cuisine'],
                $row['signatureDish'],
                $row['priceRange'],
            );

            $document = new Document($content);
            $document->id = $row['id'];
            $document->sourceType = self::SOURCE_TYPE;
            $document->sourceName = self::SOURCE_NAME;

            foreach (['name', 'city', 'cuisine', 'priceRange', 'signatureDish', 'address', 'description'] as $field) {
                $document->addMetadata($field, (string) $row[$field]);
            }
            $document->addMetadata('rating', (string) $row['rating']);

            $documents[] = $document;
        }

        return $documents;
    }

    /**
     * Build the fixed A2UI restaurant card surface. The layout is defined here,
     * not by the LLM.
     *
     * @param Document[] $restaurants
     */
    private function renderRestaurants(array $restaurants): string
    {
        $components = [
            [
                'id' => 'header',
                'component' => 'Text',
                'text' => 'Suggested restaurants',
                'variant' => 'h3',
            ],
        ];

        $rootChildren = ['header'];

        foreach (array_values($restaurants) as $i => $document) {
            $m = $document->metadata;
            $cardId = "restaurant-{$i}";
            $colId = "restaurant-{$i}-col";

            $meta = array_filter([
                $m['cuisine'] ?? null,
                $m['city'] ?? null,
                $m['priceRange'] ?? null,
                isset($m['rating']) ? '★ ' . $m['rating'] : null,
            ]);

            $components[] = ['id' => $cardId, 'component' => 'Card', 'child' => $colId];
            $components[] = [
                'id' => $colId,
                'component' => 'Column',
                'children' => ["{$cardId}-name", "{$cardId}-meta", "{$cardId}-desc", "{$cardId}-dish"],
            ];
            $components[] = [
                'id' => "{$cardId}-name",
                'component' => 'Text',
                'text' => (string) ($m['name'] ?? 'Restaurant'),
                'variant' => 'h4',
            ];
            $components[] = [
                'id' => "{$cardId}-meta",
                'component' => 'Text',
                'text' => implode('  ·  ', $meta),
                'variant' => 'caption',
            ];
            $components[] = [
                'id' => "{$cardId}-desc",
                'component' => 'Text',
                'text' => (string) ($m['description'] ?? ''),
                'variant' => 'body',
            ];
            $components[] = [
                'id' => "{$cardId}-dish",
                'component' => 'Text',
                'text' => 'Signature dish: ' . (string) ($m['signatureDish'] ?? '—'),
                'variant' => 'caption',
            ];

            $rootChildren[] = $cardId;
        }

        $components[] = [
            'id' => 'root',
            'component' => 'Column',
            'children' => $rootChildren,
        ];

        return $this->encodeSurface($components, 'surface-restaurants');
    }

    private function renderEmpty(string $query, ?string $city = null): string
    {
        $message = null !== $city && '' !== trim($city)
            ? sprintf('No restaurant found in %s for "%s".', $city, $query)
            : sprintf('No restaurant found for "%s".', $query);

        $components = [
            [
                'id' => 'root',
                'component' => 'Card',
                'child' => 'empty-text',
            ],
            [
                'id' => 'empty-text',
                'component' => 'Text',
                'text' => $message,
                'variant' => 'body',
            ],
        ];

        return $this->encodeSurface($components, 'surface-restaurants');
    }

    /**
     * @param array<int, array<string, mixed>> $components
     */
    private function encodeSurface(array $components, string $surfaceId): string
    {
        return json_encode(
            A2UIRenderTool::buildSurface($components, $surfaceId),
            \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
        );
    }

    private static function env(string $name): string
    {
        $value = $_ENV[$name] ?? getenv($name) ?: null;
        if (null === $value || '' === $value) {
            throw new \RuntimeException("Missing required environment variable: {$name}");
        }

        return (string) $value;
    }
}
