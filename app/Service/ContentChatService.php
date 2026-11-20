<?php

namespace App\Service;

use App\Models\NeuronChatMessage;
use App\Neuron\Agents\ContentRagAgent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\HttpException;
use Throwable;

class ContentChatService
{
    protected ContentRagAgent $agent;

    public function __construct(ContentRagAgent $agent)
    {
        $this->agent = $agent;
    }

    /**
     * Send a user message for a thread, trying the chosen/chat model first
     * and falling back if overloaded/timed out.
     *
     * @return array{threadId: string, reply: string, modelUsed: string, messages: Collection}
     */
    public function sendMessage(string $threadId, string $message, ?string $requestedModel = null): array
    {
        $this->agent->setThreadId($threadId);

        $primaryModel = $requestedModel ?: (string) config('central.chat_model', 'gemini-3.8-flash');
        $fallbackRaw = (string) config('central.chat_fallback_models', '');
        $fallbacks = array_values(array_filter(array_map('trim', explode(',', $fallbackRaw))));

        $candidates = array_values(array_unique(array_merge([$primaryModel], $fallbacks)));
        $userMessage = new UserMessage($message);

        $lastException = null;
        $usedModel = $primaryModel;
        $response = null;

        foreach ($candidates as $candidateModel) {
            $this->agent->setModel($candidateModel);

            try {
                $response = $this->agent->chat($userMessage);
                $usedModel = $candidateModel;
                break;
            } catch (Throwable $e) {
                $lastException = $e;

                if (!$this->isTransientError($e)) {
                    throw $e;
                }

                Log::warning('Chat model busy or timed out, trying next model', [
                    'model'   => $candidateModel,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        if ($response === null) {
            throw $lastException ?? new \RuntimeException('All configured chat models failed to respond.');
        }

        $replyText = $this->extractReplyFromState($response);

        return [
            'threadId'  => $threadId,
            'reply'     => $replyText,
            'modelUsed' => $usedModel,
            'messages'  => $this->getThreadMessages($threadId),
        ];
    }

    protected function extractReplyFromState(mixed $response): string
    {
        if (is_string($response)) {
            return trim($response);
        }

        if (!is_object($response)) {
            return '';
        }

        $message = method_exists($response, 'getMessage')
            ? $response->getMessage()
            : ($response->message ?? null);

        if (is_string($message)) {
            return trim($message);
        }

        if (is_object($message)) {
            if (method_exists($message, 'getContent')) {
                $content = $message->getContent();
                if (is_string($content)) {
                    return trim($content);
                }
                if (is_array($content)) {
                    return $this->flattenContentParts($content);
                }
            }

            if (property_exists($message, 'content')) {
                if (is_string($message->content)) {
                    return trim($message->content);
                }
                if (is_array($message->content)) {
                    return $this->flattenContentParts($message->content);
                }
            }
        }

        if (method_exists($response, 'getContent')) {
            $content = $response->getContent();
            if (is_string($content)) {
                return trim($content);
            }
            if (is_array($content)) {
                return $this->flattenContentParts($content);
            }
        }

        return '';
    }

    /**
     * @param  array<int, mixed>  $parts
     */
    protected function flattenContentParts(array $parts): string
    {
        $text = '';

        foreach ($parts as $part) {
            if (is_string($part)) {
                $text .= $part . "\n";
            } elseif (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                $text .= $part['text'] . "\n";
            } elseif (is_object($part) && method_exists($part, 'getText')) {
                $text .= (string) $part->getText() . "\n";
            } elseif (is_object($part) && property_exists($part, 'text') && is_string($part->text)) {
                $text .= $part->text . "\n";
            }
        }

        return trim($text);
    }

    protected function isTransientError(Throwable $e): bool
    {
        $msg = strtolower($e->getMessage());

        return $e instanceof HttpException
            || str_contains($msg, '503')
            || str_contains($msg, '429')
            || str_contains($msg, 'unavailable')
            || str_contains($msg, 'timed out')
            || str_contains($msg, 'timeout')
            || str_contains($msg, 'curl error 28');
    }

    public function getThreadMessages(string $threadId): Collection
    {
        return NeuronChatMessage::query()
            ->where('thread_id', $threadId)
            ->orderBy('id')
            ->get();
    }

    public function clearThread(string $threadId): void
    {
        DB::transaction(function () use ($threadId) {
            NeuronChatMessage::query()
                ->where('thread_id', $threadId)
                ->delete();
        });
    }
}