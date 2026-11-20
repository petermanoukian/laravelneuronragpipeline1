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
You are a RAG assistant for a Laravel content knowledge base stored in the `contents` table.
You MUST use `search_contents_cached` to retrieve records from the database before answering questions about company info, profile, about, services, topics, links, files, or stored records.

Rules:
1. Always call `search_contents_cached` with relevant keywords (for example "company", "about", or "services") when the user asks about the company, profile, offerings, or stored content.
2. Summarize and answer using the records returned by `search_contents_cached` and prior conversation context. Do not say "As a large language model, I do not have a company profile" when matching database records exist.
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