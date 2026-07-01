<?php

declare(strict_types=1);

namespace App\Neuron\Chat;

use NeuronAI\Chat\History\AbstractChatHistory;
use NeuronAI\Chat\Messages\Message;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Cache-backed Neuron AI chat history, keyed by AG-UI thread ID.
 *
 * Messages are serialized as JSON using Neuron AI's own serialization
 * (AbstractChatHistory::jsonSerialize()) and deserialized via
 * AbstractChatHistory::deserializeMessages() on load.
 *
 * TTL defaults to 24 hours.
 */
final class CacheAwareChatHistory extends AbstractChatHistory
{
    private readonly string $cacheKey;

    public function __construct(
        private readonly CacheInterface $cache,
        string $threadId,
        private readonly int $ttl = 86400,
        int $contextWindow = 50000,
    ) {
        parent::__construct($contextWindow);

        $this->cacheKey = 'neuron_chat_thread_'.preg_replace('/[^a-zA-Z0-9_\-]/', '_', $threadId);

        $this->loadFromCache();
    }

    /**
     * Called by AbstractChatHistory::addMessage() after every message addition.
     * Persists the full history to the cache.
     *
     * @param Message[] $messages
     */
    protected function setMessages(array $messages): void
    {
        $this->cache->delete($this->cacheKey);
        $this->cache->get(
            $this->cacheKey,
            function (ItemInterface $item) use ($messages): string {
                $item->expiresAfter($this->ttl);

                return json_encode(
                    array_map(fn (Message $m): mixed => $m->jsonSerialize(), $messages),
                    \JSON_THROW_ON_ERROR,
                );
            },
        );
    }

    /**
     * Loads persisted messages from the cache into $this->history.
     */
    private function loadFromCache(): void
    {
        $json = $this->cache->get($this->cacheKey, fn (ItemInterface $item): ?string => null);

        if (null === $json) {
            return;
        }

        $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        if (!\is_array($data)) {
            return;
        }

        $this->history = $this->deserializeMessages($data);
    }
}
