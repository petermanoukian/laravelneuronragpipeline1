<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Service\ContentCitedChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use NeuronAI\Exceptions\HttpException;
use Throwable;

class ContentChatV2Controller extends Controller
{
    protected ContentCitedChatService $chatService;

    public function __construct(ContentCitedChatService $chatService)
    {
        $this->chatService = $chatService;
    }

    public function index(Request $request): View|RedirectResponse|JsonResponse
    {
        $sessionKey = 'neuron_content_chat_v2_thread_id';

        if ($request->boolean('reset')) {
            $oldThreadId = (string) $request->session()->get($sessionKey, '');
            if ($oldThreadId !== '') {
                $this->chatService->clearThread($oldThreadId);
            }

            $newThreadId = 'content-chat-v2-' . Str::uuid();
            $request->session()->put($sessionKey, $newThreadId);

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'ok'       => true,
                    'threadId' => $newThreadId,
                    'messages' => [],
                    'sources'  => [],
                ]);
            }

            return redirect()->route('public.contents.chat.v2');
        }

        $threadId = (string) $request->session()->get($sessionKey, '');
        if ($threadId === '') {
            $threadId = 'content-chat-v2-' . Str::uuid();
            $request->session()->put($sessionKey, $threadId);
        }

        $reply = null;
        $sources = [];
        $modelUsed = null;

        if ($request->isMethod('post')) {
            $validated = $request->validate([
                'message' => ['required', 'string', 'max:2000'],
                'model'   => ['nullable', 'string', 'max:100'],
            ]);

            $requestedModel = isset($validated['model']) && trim($validated['model']) !== ''
                ? trim($validated['model'])
                : null;

            try {
                $payload = $this->chatService->sendMessage(
                    $threadId,
                    trim($validated['message']),
                    $requestedModel
                );
            } catch (Throwable $e) {
                $isTransient = $e instanceof HttpException
                    || str_contains(strtolower($e->getMessage()), '503')
                    || str_contains(strtolower($e->getMessage()), '429')
                    || str_contains(strtolower($e->getMessage()), 'unavailable')
                    || str_contains(strtolower($e->getMessage()), 'timed out')
                    || str_contains(strtolower($e->getMessage()), 'timeout');

                if ($isTransient && ($request->expectsJson() || $request->ajax())) {
                    return response()->json([
                        'ok'      => false,
                        'message' => 'Chat model is temporarily busy or timed out. Please try again or switch models.',
                    ], 503);
                }

                throw $e;
            }

            $reply = $payload['reply'];
            $sources = $payload['sources'] ?? [];
            $modelUsed = $payload['modelUsed'] ?? $requestedModel;
            $messages = $payload['messages'];

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'ok'        => true,
                    'threadId'  => $payload['threadId'],
                    'modelUsed' => $modelUsed,
                    'reply'     => $reply,
                    'sources'   => $sources,
                    'messages'  => $this->formatMessagesForJson($messages),
                ]);
            }
        } else {
            $messages = $this->chatService->getThreadMessages($threadId);
        }

        return view('public.chat-v2', [
            'threadId'  => $threadId,
            'messages'  => $messages,
            'reply'     => $reply,
            'sources'   => $sources,
            'modelUsed' => $modelUsed,
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
}