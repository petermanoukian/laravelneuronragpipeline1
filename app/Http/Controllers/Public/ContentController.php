<?php

namespace App\Http\Controllers\Public;



use App\Http\Controllers\Controller;
use App\Http\Requests\SearchContentRequest;
use App\Service\ContentChatService;
use App\Service\ContentSearchService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Illuminate\Http\JsonResponse;



class ContentController extends Controller
{
    protected ContentSearchService $searchService;
    protected ContentChatService $chatService;

    public function __construct(
        ContentSearchService $searchService,
        ContentChatService $chatService
    ) {
        $this->searchService = $searchService;
        $this->chatService = $chatService;
    }

    /**
     * Handle GET (initial page load) and POST (search submission) for public RAG search.
     */
    public function searchgemini1(SearchContentRequest $request): View
    {
        $validated = $request->validated();
        $query = trim((string) ($validated['q'] ?? ''));

        if ($query === '') {
            return view('public.searchgemini1');
        }

        // Controller defines operational RAG parameters
        $maxContextItems = 5;
        $model = config('central.default_model');
        $temperature = 0.2;
        $includeMedia = true;
        $maxInlineBytes = 10 * 1024 * 1024; // 10MB

        $payload = $this->searchService->searchWithRag(
            $query,
            $maxContextItems,
            $model,
            $temperature,
            $includeMedia,
            $maxInlineBytes
        );

        return view('public.searchgemini1', [
            'results'  => $payload['results'],
            'aiAnswer' => $payload['aiAnswer'],
            'query'    => $query,
        ]);
    }

    public function chat(Request $request): View|RedirectResponse|JsonResponse
    {
        $sessionKey = 'neuron_content_chat_thread_id';

        if ($request->boolean('reset')) {
            $oldThreadId = (string) $request->session()->get($sessionKey, '');
            if ($oldThreadId !== '') {
                $this->chatService->clearThread($oldThreadId);
            }
            $newThreadId = 'content-chat-' . Str::uuid();
            $request->session()->put($sessionKey, $newThreadId);

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'ok'       => true,
                    'threadId' => $newThreadId,
                    'messages' => [],
                ]);
            }

            return redirect()->route('public.contents.chat');
        }

        $threadId = (string) $request->session()->get($sessionKey, '');
        if ($threadId === '') {
            $threadId = 'content-chat-' . Str::uuid();
            $request->session()->put($sessionKey, $threadId);
        }

        $reply = null;

        if ($request->isMethod('post')) {
            $validated = $request->validate([
                'message' => ['required', 'string', 'max:2000'],
                'model'   => ['nullable', 'string', 'max:100'],
            ]);

            $requestedModel = isset($validated['model']) && trim($validated['model']) !== ''
                ? trim($validated['model'])
                : null;

            $payload = $this->chatService->sendMessage(
                $threadId,
                trim($validated['message']),
                $requestedModel
            );

            $reply = $payload['reply'];
            $messages = $payload['messages'];

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'ok'        => true,
                    'threadId'  => $payload['threadId'],
                    'modelUsed' => $payload['modelUsed'] ?? $requestedModel,
                    'reply'     => $reply,
                    'messages'  => $this->formatMessagesForJson($messages),
                ]);
            }
        } else {
            $messages = $this->chatService->getThreadMessages($threadId);
        }

        return view('public.chat', [
            'threadId' => $threadId,
            'messages' => $messages,
            'reply'    => $reply,
        ]);
    }

    /**
     * Normalize stored NeuronChatMessage rows into simple {role, text} items for JSON responses.
     *
     * @param  \Illuminate\Support\Collection<int, mixed>  $messages
     * @return array<int, array{role: string, text: string}>
     */
    protected function formatMessagesForJson($messages): array
    {
        $formatted = [];

        foreach ($messages as $msg) {
            $rawPayload = is_string($msg->message)
                ? json_decode($msg->message, true)
                : (array) ($msg->message ?? []);

            $role = (string) ($rawPayload['role'] ?? ($msg->role ?? 'user'));
            $text = '';

            if (is_array($rawPayload['content'] ?? null)) {
                foreach ($rawPayload['content'] as $part) {
                    if (is_string($part)) {
                        $text .= $part . "\n";
                    } elseif (is_array($part) && isset($part['text'])) {
                        $text .= (string) $part['text'] . "\n";
                    }
                }
            } elseif (is_string($rawPayload['content'] ?? null)) {
                $text = $rawPayload['content'];
            } elseif (is_string($msg->message ?? null)) {
                $text = $msg->message;
            }

            $text = trim($text);

            if ($text !== '' && in_array($role, ['user', 'assistant', 'model'], true)) {
                $formatted[] = [
                    'role' => $role === 'user' ? 'user' : 'assistant',
                    'text' => $text,
                ];
            }
        }

        return $formatted;
    }

    /**
     * Normalize stored NeuronChatMessage rows into simple {role, text} items for JSON responses.
     *
     * @param  \Illuminate\Support\Collection<int, mixed>  $messages
     * @return array<int, array{role: string, text: string}>
     */
    
}