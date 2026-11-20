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
        $this->modelName = $model ?: (string) config('central.chat_model', 'gemini-3.8-flash');
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
You are a helpful RAG assistant for a Laravel content knowledge base.
You have access to the search_contents tool to look up records from the database.

Guidelines:
1. Use search_contents whenever the user asks about stored content, topics, services, links, or files.
2. Synthesize accurate, concise answers strictly grounded in the tool output and earlier conversation context.
3. If no matching records are found, say so clearly without fabricating facts.
4. Respond clearly in the same language as the user, and mention relevant record titles, IDs, or links when helpful.
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