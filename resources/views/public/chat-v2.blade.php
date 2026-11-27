@extends('layouts.public')

@section('title', 'Chat V2 - RAG V2')

@section('content')
<div class="max-w-3xl mx-auto">
    <div class="flex items-center justify-between gap-4 mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Content RAG Chat V2</h1>
            <p class="text-xs text-gray-500 mt-1">Cached search + cited sources + model switching</p>
        </div>

        <div class="flex items-center gap-3">
            <select
                id="chat-v2-model-select"
                class="text-sm border border-gray-300 rounded-lg px-3 py-2 bg-white text-gray-800 focus:outline-none focus:ring-2 focus:ring-blue-500"
            >
                <option value="" selected>Default ({{ config('central.chat_model', config('central.default_model', 'gemini-1.5-pro')) }})</option>
                <option value="gemini-1.5-pro">gemini-1.5-pro</option>
                <option value="gemini-3.8-flash">gemini-3.8-flash</option>
                <option value="gemini-3.5-flash-lite">gemini-3.5-flash-lite</option>
                <option value="gemini-2.5-flash">gemini-2.5-flash</option>
            </select>

            <form id="reset-chat-v2-form" method="POST" action="{{ route('public.contents.chat.v2') }}">
                @csrf
                <input type="hidden" name="reset" value="1">
                <button
                    id="reset-chat-v2-btn"
                    type="submit"
                    class="text-sm bg-gray-200 hover:bg-gray-300 text-gray-800 font-semibold px-4 py-2 rounded-lg transition disabled:opacity-50"
                >
                    New Chat
                </button>
            </form>
        </div>
    </div>

    <div id="chat-v2-error-box" class="hidden mb-6 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded text-sm"></div>

    <div id="chat-v2-messages" class="bg-white rounded-lg shadow-md p-6 mb-6 space-y-4 max-h-[540px] overflow-y-auto">
        @forelse($messages as $msg)
            @php
                $rawPayload = is_string($msg->message) ? json_decode($msg->message, true) : (array) ($msg->message ?? []);
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
            @endphp

            @if($text !== '' && in_array($role, ['user', 'assistant', 'model'], true))
                @php $isUser = $role === 'user'; @endphp
                <div class="p-4 rounded-lg {{ $isUser ? 'bg-blue-50 border border-blue-200 ml-8' : 'bg-gray-100 border border-gray-200 mr-8' }}">
                    <div class="text-xs font-bold uppercase tracking-wider {{ $isUser ? 'text-blue-800' : 'text-gray-700' }} mb-1">
                        {{ $isUser ? 'You' : 'Assistant' }}
                    </div>
                    <div class="text-gray-800 whitespace-pre-line">{{ $text }}</div>
                </div>
            @endif
        @empty
            <div id="chat-v2-empty-state" class="text-center text-gray-500 py-2">
                Ask a question about your stored content to start a cited conversation.
            </div>
        @endforelse
    </div>

    <form id="chat-v2-form" method="POST" action="{{ route('public.contents.chat.v2') }}" class="bg-white p-4 rounded-lg shadow-md flex gap-3">
        @csrf
        <input
            id="chat-v2-input"
            type="text"
            name="message"
            value="{{ old('message') }}"
            placeholder="Ask about your content records..."
            class="flex-1 border border-gray-300 rounded-lg px-4 py-2 text-gray-800 focus:outline-none focus:ring-2 focus:ring-blue-500"
            autofocus
        >
        <button
            id="chat-v2-submit-btn"
            type="submit"
            class="bg-blue-600 hover:bg-blue-700 text-white font-semibold px-6 py-2 rounded-lg transition disabled:opacity-50"
        >
            Send
        </button>
    </form>
</div>

<script>
(function () {
    const chatUrl = @json(route('public.contents.chat.v2'));
    const baseAssetUrl = @json(asset('/'));
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
        || document.querySelector('#chat-v2-form input[name="_token"]')?.value;

    const form = document.getElementById('chat-v2-form');
    const resetForm = document.getElementById('reset-chat-v2-form');
    const modelSelect = document.getElementById('chat-v2-model-select');
    const input = document.getElementById('chat-v2-input');
    const submitBtn = document.getElementById('chat-v2-submit-btn');
    const resetBtn = document.getElementById('reset-chat-v2-btn');
    const messagesBox = document.getElementById('chat-v2-messages');
    const errorBox = document.getElementById('chat-v2-error-box');

    function showError(msg) {
        errorBox.textContent = msg;
        errorBox.classList.remove('hidden');
    }

    function clearError() {
        errorBox.textContent = '';
        errorBox.classList.add('hidden');
    }

    function removePendingBubble() {
        document.getElementById('chat-v2-pending-bubble')?.remove();
    }

    function toAssetUrl(relPath) {
        if (!relPath) return null;
        if (/^https?:\/\//i.test(relPath)) return relPath;
        return baseAssetUrl.replace(/\/+$/, '') + '/' + String(relPath).replace(/^\/+/, '');
    }

    function buildSourcesElement(sources) {
        if (!Array.isArray(sources) || sources.length === 0) {
            return null;
        }

        const container = document.createElement('div');
        container.className = 'mt-3 pt-3 border-t border-gray-300/70 space-y-3';

        const dbSources = sources.filter(src => src && !src.is_files2);
        const freeFiles = sources.filter(src => src && Boolean(src.is_files2));

        if (dbSources.length > 0) {
            const dbBox = document.createElement('div');
            dbBox.className = 'border-2 border-red-500 rounded-lg p-3 bg-white space-y-2';

            const heading = document.createElement('div');
            heading.className = 'text-[11px] font-semibold uppercase tracking-wider text-red-700';
            heading.textContent = 'Sources & Attachments';
            dbBox.appendChild(heading);

            const chipsWrap = document.createElement('div');
            chipsWrap.className = 'flex flex-wrap gap-2';

            dbSources.forEach(src => {
                const label = src.id !== null && src.id !== undefined
                    ? `#${src.id} — ${src.title || 'Record'}`
                    : (src.title || 'Record');

                if (src.url && /^https?:\/\//i.test(src.url)) {
                    const link = document.createElement('a');
                    link.href = src.url;
                    link.target = '_blank';
                    link.rel = 'noopener noreferrer';
                    link.className = 'inline-flex items-center text-xs bg-white hover:bg-blue-50 text-blue-700 border border-blue-200 rounded-md px-2.5 py-1 transition';
                    link.textContent = label;
                    chipsWrap.appendChild(link);
                } else {
                    const chip = document.createElement('span');
                    chip.className = 'inline-flex items-center text-xs bg-white text-gray-700 border border-gray-200 rounded-md px-2.5 py-1';
                    chip.textContent = src.file ? `${label} (${String(src.file).split('/').pop()})` : label;
                    chipsWrap.appendChild(chip);
                }

                if (src.file) {
                    const fLink = document.createElement('a');
                    fLink.href = toAssetUrl(src.file);
                    fLink.target = '_blank';
                    fLink.rel = 'noopener noreferrer';
                    fLink.className = 'inline-flex items-center text-xs bg-gray-50 hover:bg-gray-200 text-gray-800 border border-gray-300 rounded-md px-2.5 py-1 font-medium transition';
                    fLink.textContent = 'File: ' + String(src.file).split('/').pop();
                    chipsWrap.appendChild(fLink);
                }

                // here perter: render second file link when present
                if (src.file_two) {
                    const f2Link = document.createElement('a');
                    f2Link.href = toAssetUrl(src.file_two);
                    f2Link.target = '_blank';
                    f2Link.rel = 'noopener noreferrer';
                    f2Link.className = 'inline-flex items-center text-xs bg-gray-50 hover:bg-gray-200 text-gray-800 border border-gray-300 rounded-md px-2.5 py-1 font-medium transition';
                    f2Link.textContent = 'File 2: ' + String(src.file_two).split('/').pop();
                    chipsWrap.appendChild(f2Link);
                }

                if (src.image) {
                    const imgBox = document.createElement('div');
                    imgBox.className = 'w-full pt-1';

                    const img = document.createElement('img');
                    img.src = toAssetUrl(src.image);
                    img.alt = src.title || 'Attached image';
                    img.className = 'max-h-48 rounded-lg border border-gray-200 object-contain bg-white';
                    imgBox.appendChild(img);
                    dbBox.appendChild(imgBox);
                }
            });

            dbBox.appendChild(chipsWrap);
            container.appendChild(dbBox);
        }

        // here perter: render free files (files2) in their own blue-bordered section with an H1
        if (freeFiles.length > 0) {
            const freeBox = document.createElement('div');
            freeBox.className = 'border-2 border-blue-500 rounded-lg p-3 bg-white space-y-2';

            const freeHeading = document.createElement('h1');
            freeHeading.className = 'text-lg font-bold text-blue-700';
            freeHeading.textContent = 'Free Files (files2)';
            freeBox.appendChild(freeHeading);

            freeFiles.forEach(src => {
                const row = document.createElement('div');
                row.className = 'flex items-center justify-between gap-2 border border-blue-300 rounded-md px-2.5 py-1.5 text-xs';

                const titleEl = document.createElement('span');
                titleEl.className = 'font-medium text-gray-900';
                titleEl.textContent = src.title || String(src.file || src.image || '').split('/').pop();
                row.appendChild(titleEl);

                const assetPath = src.file || src.image;
                if (assetPath) {
                    const link = document.createElement('a');
                    link.href = toAssetUrl(assetPath);
                    link.target = '_blank';
                    link.rel = 'noopener noreferrer';
                    link.className = 'text-blue-600 hover:underline font-medium';
                    link.textContent = 'Open File';
                    row.appendChild(link);
                }

                freeBox.appendChild(row);
            });

            container.appendChild(freeBox);
        }

        return container.childNodes.length > 0 ? container : null;
    }

    function appendBubble(role, text, id = null, badgeText = null, sources = []) {
        const emptyEl = document.getElementById('chat-v2-empty-state');
        if (emptyEl) emptyEl.remove();

        const isUser = role === 'user';
        const wrapper = document.createElement('div');
        if (id) wrapper.id = id;
        wrapper.className = isUser
            ? 'p-4 rounded-lg bg-blue-50 border border-blue-200 ml-8'
            : 'p-4 rounded-lg bg-gray-100 border border-gray-200 mr-8';

        const label = document.createElement('div');
        label.className = isUser
            ? 'text-xs font-bold uppercase tracking-wider text-blue-800 mb-1'
            : 'text-xs font-bold uppercase tracking-wider text-gray-700 mb-1';
        label.textContent = isUser
            ? 'You'
            : (badgeText ? `Assistant · ${badgeText}` : 'Assistant');

        const body = document.createElement('div');
        body.className = 'text-gray-800 whitespace-pre-line';
        body.textContent = text;

        wrapper.appendChild(label);
        wrapper.appendChild(body);

        if (!isUser) {
            const sourcesEl = buildSourcesElement(sources);
            if (sourcesEl) {
                wrapper.appendChild(sourcesEl);
            }
        }

        messagesBox.appendChild(wrapper);
        messagesBox.scrollTop = messagesBox.scrollHeight;
        return wrapper;
    }

    function renderAllMessages(list, latestReply = null, modelUsed = null, sources = []) {
        messagesBox.innerHTML = '';
        if (!Array.isArray(list) || list.length === 0) {
            if (latestReply) {
                appendBubble('assistant', latestReply, null, modelUsed, sources);
                return;
            }
            messagesBox.innerHTML = '<div id="chat-v2-empty-state" class="text-center text-gray-500 py-8">Ask a question about your stored content to start a cited conversation.</div>';
            return;
        }

        list.forEach((item, index) => {
            const isLastAssistant = item.role !== 'user' && index === list.length - 1;
            appendBubble(
                item.role,
                item.text,
                null,
                isLastAssistant ? modelUsed : null,
                isLastAssistant ? sources : []
            );
        });
    }

    async function postJson(payload) {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 45000);

        try {
            const response = await fetch(chatUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify(payload),
                signal: controller.signal,
            });

            const rawText = await response.text();
            let data = null;

            try {
                data = rawText ? JSON.parse(rawText) : {};
            } catch (parseErr) {
                throw new Error(`Non-JSON response (HTTP ${response.status}): ${rawText.slice(0, 180) || 'empty body'}`);
            }

            if (!response.ok) {
                const firstErr = data?.errors?.message?.[0] || data?.message || `HTTP ${response.status} request failed.`;
                throw new Error(firstErr);
            }

            return data;
        } finally {
            clearTimeout(timeoutId);
        }
    }

    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        clearError();

        const text = input.value.trim();
        if (!text) return;

        const chosenModel = modelSelect ? modelSelect.value : '';

        input.value = '';
        input.disabled = true;
        submitBtn.disabled = true;
        resetBtn.disabled = true;
        if (modelSelect) modelSelect.disabled = true;

        appendBubble('user', text);
        appendBubble('assistant', 'Thinking...', 'chat-v2-pending-bubble', chosenModel || null);

        try {
            const payload = { message: text };
            if (chosenModel) {
                payload.model = chosenModel;
            }

            const data = await postJson(payload);
            removePendingBubble();

            // here perter: merge standaloneFiles into the assistant bubble sources so buildSourcesElement renders the blue section
            const combinedSources = [
                ...(Array.isArray(data.sources) ? data.sources : []),
                ...(Array.isArray(data.standaloneFiles) ? data.standaloneFiles.map(sf => ({
                    title: sf.filename || String(sf.relative_path || '').split('/').pop(),
                    file: sf.relative_path || null,
                    is_files2: true,
                })) : []),
            ];

            if (Array.isArray(data.messages) && data.messages.length > 0) {
                renderAllMessages(data.messages, data.reply, data.modelUsed || chosenModel || null, combinedSources);
            } else if (data.reply) {
                appendBubble('assistant', data.reply, null, data.modelUsed || chosenModel || null, combinedSources);
            } else {
                showError('Empty response received from server.');
            }
        } catch (err) {
            removePendingBubble();
            if (err.name === 'AbortError') {
                showError('Request timed out after 45s. Try again or switch to another model.');
            } else {
                showError(err.message || 'Network error while sending message.');
            }
        } finally {
            input.disabled = false;
            submitBtn.disabled = false;
            resetBtn.disabled = false;
            if (modelSelect) modelSelect.disabled = false;
            input.focus();
        }
    });

    resetForm.addEventListener('submit', async function (e) {
        e.preventDefault();
        clearError();
        resetBtn.disabled = true;
        submitBtn.disabled = true;

        try {
            await postJson({ reset: true });
            renderAllMessages([]);
        } catch (err) {
            showError(err.message || 'Could not reset chat.');
        } finally {
            resetBtn.disabled = false;
            submitBtn.disabled = false;
            input.focus();
        }
    });

    messagesBox.scrollTop = messagesBox.scrollHeight;
})();
</script>
@endsection
