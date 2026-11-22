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
You are a RAG assistant for a Laravel content knowledge base stored in the `contents` table.
You MUST call `search_contents` to retrieve records from the database before answering questions about company info, profile, about, services, topics, links, files, or stored records.

Rules:
1. Always call `search_contents` with relevant keywords (such as "company", "about", or "services") whenever the user asks about the company, profile, offerings, or stored content.
2. Answer using the records, URLs, and file contents returned by `search_contents` and prior conversation context. Do not say "I am an AI assistant. I don't have a company of my own" when matching database records exist.
3. Mention relevant record titles, IDs, or links when helpful.
4. Only state that no matching records were found if `search_contents` returns no matching records.
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