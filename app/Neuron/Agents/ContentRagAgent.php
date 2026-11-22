<?php

namespace App\Neuron\Agents;

use App\Interface\ContentRepositoryInterface;
use App\Models\NeuronChatMessage;
use App\Neuron\Tools\SearchContentsTool;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\History\EloquentChatHistory;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Gemini\Gemini;

class ContentRagAgent extends Agent
{
    protected ContentRepositoryInterface $contentRepo;
    protected string $modelName;

    public function __construct(
        ContentRepositoryInterface $contentRepo,
        ?string $model = null
    ) {
        $this->contentRepo = $contentRepo;
        $this->modelName = $model ?: (string) config('central.chat_model', 'gemini-2.5-flash');
    }

    public function setModel(string $model): static
    {
        $this->modelName = $model;

        return $this;
    }

    protected function provider(): AIProviderInterface
    {
        $apiKey = (string) config('central.gemini_key', '');

        return new Gemini(
            key: $apiKey,
            model: $this->modelName
        );
    }

protected function instructions(): string
    {
        return <<<'PROMPT'
You are an assistant grounded in a Laravel content knowledge base stored in the `contents` table.
Use `search_contents` to search stored records for relevant answers before giving generic AI or meta responses.

Rules:
1. Call `search_contents` with relevant keywords whenever answering a question that could be covered by stored records, notes, files, links, or knowledge base items.
2. Answer directly using matching records, URLs, and file contents returned by `search_contents` and prior conversation context. Never say "As an AI" or "As a large language model" unless explicitly asked about being an AI.
3. Mention relevant record titles, IDs, or links when helpful.
4. If `search_contents` returns no matching records, say no matching records were found in the knowledge base rather than giving a generic LLM boilerplate answer.
5. Respond in the same language as the user.
PROMPT;
    }

    protected function chatHistory(): ChatHistoryInterface
    {
        $threadId = method_exists($this, 'getThreadId')
            ? (string) $this->getThreadId()
            : 'default-thread';

        return new EloquentChatHistory(
            modelClass: NeuronChatMessage::class,
            threadId: $threadId,
            contextWindow: 100000
        );
    }

    protected function tools(): array
    {
        return [
            new SearchContentsTool($this->contentRepo),
        ];
    }
}