<?php

namespace App\Neuron\Agents;

use App\Interface\ContentRepositoryInterface;
use App\Models\NeuronChatMessage;
use App\Neuron\Tools\CachedSearchContentsTool;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\History\EloquentChatHistory;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Gemini\Gemini;

class ContentCitedRagAgent extends Agent
{
    protected ContentRepositoryInterface $contentRepo;
    protected string $modelName;

    public function __construct(
        ContentRepositoryInterface $contentRepo,
        ?string $model = null
    ) {
        $this->contentRepo = $contentRepo;
        $this->modelName = $model
            ?: (string) config('central.chat_model', config('central.default_model', 'gemini-2.5-flash'));
    }

    public function setModel(string $model): static
    {
        $this->modelName = $model;

        return $this;
    }

    public function getModel(): string
    {
        return $this->modelName;
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
Use `search_contents_cached` to retrieve records from the database before answering questions that may be covered by stored records.

Rules:
1. Call `search_contents_cached` with relevant keywords whenever the user asks about topics, profiles, services, notes, files, links, or stored content.
2. Summarize and answer using the records returned by `search_contents_cached` and prior conversation context. Never use generic "As a large language model..." boilerplate when records or context exist.
3. Reference matching record titles and IDs when citing records.
4. Only state that no matching records were found if `search_contents_cached` returns no matching records.
5. Respond in the same language as the user.
PROMPT;
    }

    protected function chatHistory(): ChatHistoryInterface
    {
        $threadId = method_exists($this, 'getThreadId')
            ? (string) $this->getThreadId()
            : 'default-cited-thread';

        return new EloquentChatHistory(
            modelClass: NeuronChatMessage::class,
            threadId: $threadId,
            contextWindow: 100000
        );
    }

    /**
     * @return array<int, \NeuronAI\Tools\ToolInterface>
     */
    protected function tools(): array
    {
        return [
            new CachedSearchContentsTool($this->contentRepo),
        ];
    }
}