<?php

namespace App\Service;

use App\Models\NeuronChatMessage;
use App\Neuron\Agents\ContentCitedRagAgent;
use App\Neuron\Tools\CachedSearchContentsTool;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\HttpException;
use Throwable;

class ContentCitedChatService
{
    protected ContentCitedRagAgent $agent;

    public function __construct(ContentCitedRagAgent $agent)
    {
        $this->agent = $agent;
    }

    /**
     * Send a message in a thread and return the reply, used model, captured sources, and persisted messages.
     *
     * @return array{
     *     threadId: string,
     *     reply: string,
     *     modelUsed: string,
     *     sources: array<int, array{id: int|string|null, title: string, url: ?string, file: ?string, image: ?string}>,
     *     messages: Collection<int, NeuronChatMessage>
     * }
     */

    public function sendMessage(string $threadId, string $message, ?string $requestedModel = null): array
    {
        $startTime = microtime(true);
        $this->agent->setThreadId($threadId);

        CachedSearchContentsTool::resetCapturedSources();

        // here perter: scan uploads/content/files2 for standalone files matching the chat message
        $standaloneFiles = $this->findStandaloneFilesForChat($message);

        $primaryModel = $requestedModel
            ?: (string) config('central.chat_model', config('central.default_model', 'gemini-1.5-pro'));

        $fallbackRaw = (string) config('central.chat_fallback_models', '');
        $fallbacks = array_values(array_filter(array_map('trim', explode(',', $fallbackRaw))));

        // Keep at most 1 fallback and exclude the primary model so we don't stack long waits past 45s
        $firstFallback = null;
        foreach ($fallbacks as $fb) {
            if ($fb !== '' && $fb !== $primaryModel) {
                $firstFallback = $fb;
                break;
            }
        }

        $candidates = $firstFallback ? [$primaryModel, $firstFallback] : [$primaryModel];
        $enrichedMessage = $this->buildMessageWithStandaloneFiles($message, $standaloneFiles);
        $userMessage = new UserMessage($enrichedMessage);

        Log::info('ChatV2 start', [
            'thread_id'  => $threadId,
            'candidates' => $candidates,
            'has_key'    => (string) config('central.gemini_key', '') !== '',
        ]);

        $lastException = null;
        $usedModel = $primaryModel;
        $state = null;

        foreach ($candidates as $candidateModel) {
            $modelStart = microtime(true);
            $this->agent->setModel($candidateModel);

            try {
                $state = $this->agent->chat($userMessage);
                $usedModel = $candidateModel;

                Log::info('ChatV2 model succeeded', [
                    'thread_id'  => $threadId,
                    'model'      => $candidateModel,
                    'elapsed_ms' => round((microtime(true) - $modelStart) * 1000),
                ]);
                break;
            } catch (Throwable $e) {
                $lastException = $e;

                Log::warning('ChatV2 model failed', [
                    'thread_id'  => $threadId,
                    'model'      => $candidateModel,
                    'elapsed_ms' => round((microtime(true) - $modelStart) * 1000),
                    'error'      => $e->getMessage(),
                ]);

                if (!$this->isTransientError($e)) {
                    throw $e;
                }
            }
        }

        if ($state === null) {
            throw $lastException ?? new \RuntimeException('All configured chat models failed to respond.');
        }

        $messages = $this->getThreadMessages($threadId);
        $replyText = $this->extractReplyFromState($state);
        if ($replyText === '') {
            $replyText = $this->extractLatestAssistantTextFromMessages($messages);
        }

        $sources = CachedSearchContentsTool::getCapturedSources();
        if ($replyText === '' && !empty($sources)) {
            $titles = array_map(fn ($s) => (string) ($s['title'] ?? 'Record'), $sources);
            $replyText = 'Found matching records in the knowledge base: ' . implode(', ', $titles) . '.';
        }

        Log::info('ChatV2 done', [
            'thread_id'  => $threadId,
            'total_ms'   => round((microtime(true) - $startTime) * 1000),
            'sources_ct' => count($sources),
        ]);

        return [
            'threadId'        => $threadId,
            'reply'           => $replyText,
            'modelUsed'       => $usedModel,
            'sources'         => $sources,
            'standaloneFiles' => $standaloneFiles,
            'messages'        => $messages,
        ];
    }

    /**
     * @param  Collection<int, NeuronChatMessage>  $messages
     */
    protected function extractLatestAssistantTextFromMessages(Collection $messages): string
    {
        foreach ($messages->reverse() as $msg) {
            $rawPayload = is_string($msg->message)
                ? json_decode($msg->message, true)
                : (array) ($msg->message ?? []);

            $role = (string) ($rawPayload['role'] ?? ($msg->role ?? ''));
            if (!in_array($role, ['assistant', 'model'], true)) {
                continue;
            }

            $content = $rawPayload['content'] ?? null;
            if (is_string($content) && trim($content) !== '') {
                return trim($content);
            }
            if (is_array($content)) {
                $flat = $this->flattenContentParts($content);
                if ($flat !== '') {
                    return $flat;
                }
            }
        }

        return '';
    }

    /**
     * @return Collection<int, NeuronChatMessage>
     */
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

    protected function isTransientError(Throwable $e): bool
    {
        $msg = strtolower($e->getMessage());

        return $e instanceof HttpException
            || str_contains($msg, '503')
            || str_contains($msg, '429')
            || str_contains($msg, 'unavailable')
            || str_contains($msg, 'high demand')
            || str_contains($msg, 'timed out')
            || str_contains($msg, 'timeout')
            || str_contains($msg, 'curl error 28');
    }

    protected function extractReplyFromState(mixed $state): string
    {
        if (is_string($state)) {
            return trim($state);
        }

        if (!is_object($state)) {
            return '';
        }

        $message = method_exists($state, 'getMessage')
            ? $state->getMessage()
            : ($state->message ?? null);

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

        if (method_exists($state, 'getContent')) {
            $content = $state->getContent();
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

// here perter: scan uploads/content/files2 for standalone files matching the chat message
    /**
     * @return array<int, array<string, mixed>>
     */
    protected function findStandaloneFilesForChat(string $message, int $maxInlineBytes = 10485760): array
    {
        $clean = trim($message);
        if ($clean === '') {
            return [];
        }

        $terms = preg_split('/\s+/u', $clean, -1, PREG_SPLIT_NO_EMPTY) ?: [$clean];
        $searchTerms = array_values(array_unique(array_filter(array_merge([$clean], $terms))));

        if (!app()->bound(ContentSearchService::class)) {
            return [];
        }

        $searchService = app(ContentSearchService::class);
        $ref = new \ReflectionMethod($searchService, 'findMatchingStoredFiles');
        $ref->setAccessible(true);

        $allMatches = $ref->invoke($searchService, $searchTerms, $maxInlineBytes);
        if (!is_array($allMatches)) {
            return [];
        }

        return array_values(array_filter(
            $allMatches,
            static fn (array $f): bool => ($f['is_files2'] ?? false) === true
        ));
    }

    // here perter: append extracted standalone files2 text into the chat prompt without altering DB sources
    /**
     * @param  array<int, array<string, mixed>>  $standaloneFiles
     */
    protected function buildMessageWithStandaloneFiles(string $message, array $standaloneFiles): string
    {
        if (empty($standaloneFiles)) {
            return $message;
        }

        $blocks = ["=== STANDALONE FILES CONTEXT (uploads/content/files2) ==="];

        foreach ($standaloneFiles as $idx => $file) {
            $num = $idx + 1;
            $name = (string) ($file['filename'] ?? 'file');
            $rel = (string) ($file['relative_path'] ?? '');
            $excerpt = (string) ($file['excerpt'] ?? '');

            $blocks[] = "[File #{$num}: {$name} ({$rel})]";
            if ($excerpt !== '') {
                $blocks[] = mb_substr($excerpt, 0, 3000);
            }
        }

        $blocks[] = "=== USER MESSAGE ===\n{$message}";

        return implode("\n\n", $blocks);
    }




}