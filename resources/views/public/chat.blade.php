@extends('layouts.public')

@section('title', 'Chat - RAG V2')

@section('content')
<div class="max-w-3xl mx-auto">
    <div class="flex items-center justify-between gap-4 mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Content RAG Chat</h1>

        <div class="flex items-center gap-3">
            <select
                id="chat-model-select"
                class="text-sm border border-gray-300 rounded-lg px-3 py-2 bg-white text-gray-800 focus:outline-none focus:ring-2 focus:ring-blue-500"
            >
                <option value="" selected>Default ({{ config('central.chat_model', 'gemini-2.5-flash') }})</option>
                <option value="gemini-2.5-flash">gemini-2.5-flash</option>
                <option value="gemini-3.8-flash">gemini-3.8-flash</option>
                <option value="gemini-3.5-flash-lite">gemini-3.5-flash-lite</option>
                <option value="gemini-1.5-pro">gemini-1.5-pro</option>
            </select>

            <form id="reset-chat-form" method="POST" action="{{ route('public.contents.chat') }}">
                @csrf
                <input type="hidden" name="reset" value="1">
                <button
                    id="reset-chat-btn"
                    type="submit"
                    class="text-sm bg-gray-200 hover:bg-gray-300 text-gray-800 font-semibold px-4 py-2 rounded-lg transition disabled:opacity-50"
                >
                    New Chat
                </button>
            </form>
        </div>
    </div>

    <div id="chat-error-box" class="hidden mb-6 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded text-sm"></div>

    <div id="chat-messages" class="bg-white rounded-lg shadow-md p-6 mb-6 space-y-4 max-h-[520px] overflow-y-auto">
        @forelse($messages as $msg)
            @php
                $rawPayload = is_string($msg->message) ? json_decode($msg->message, true) : (array) $msg->message;
                $role = $rawPayload['role'] ?? ($msg->role ?? 'user');
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
                } elseif (is_string($msg->message)) {
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
            <div id="chat-empty-state" class="text-center text-gray-500 py-2">
                Ask a question about your stored content to start a conversation.
            </div>
        @endforelse
    </div>

    <div id="chat-v1-media" class="hidden bg-white rounded-lg shadow-md p-4 mb-6 space-y-3"></div>

    <form id="chat-form" method="POST" action="{{ route('public.contents.chat') }}" class="bg-white p-4 rounded-lg shadow-md flex gap-3">
        @csrf
        <input
            id="chat-input"
            type="text"
            name="message"
            value="{{ old('message') }}"
            placeholder="Ask about your content records..."
            class="flex-1 border border-gray-300 rounded-lg px-4 py-2 text-gray-800 focus:outline-none focus:ring-2 focus:ring-blue-500"
            autofocus
        >
        <button
            id="chat-submit-btn"
            type="submit"
            class="bg-blue-600 hover:bg-blue-700 text-white font-semibold px-6 py-2 rounded-lg transition disabled:opacity-50"
        >
            Send
        </button>
    </form>
</div>

<script>
(function () {
    const chatUrl = @json(route('public.contents.chat'));
    const baseAssetUrl = @json(asset('/'));
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
        || document.querySelector('#chat-form input[name="_token"]')?.value;

    const form = document.getElementById('chat-form');
    const resetForm = document.getElementById('reset-chat-form');
    const modelSelect = document.getElementById('chat-model-select');
    const input = document.getElementById('chat-input');
    const submitBtn = document.getElementById('chat-submit-btn');
    const resetBtn = document.getElementById('reset-chat-btn');
    const messagesBox = document.getElementById('chat-messages');
    const mediaBox = document.getElementById('chat-v1-media');
    const errorBox = document.getElementById('chat-error-box');

    function showError(msg) {
        errorBox.textContent = msg;
        errorBox.classList.remove('hidden');
    }

    function clearError() {
        errorBox.textContent = '';
        errorBox.classList.add('hidden');
    }

    function removePendingBubble() {
        document.getElementById('chat-pending-bubble')?.remove();
    }

    function toAssetUrl(relPath) {
        if (!relPath) return null;
        if (/^https?:\/\//i.test(relPath)) return relPath;
        return baseAssetUrl.replace(/\/+$/, '') + '/' + String(relPath).replace(/^\/+/, '');
    }

    function renderMediaAttachments(sources) {
        mediaBox.innerHTML = '';
        if (!Array.isArray(sources) || sources.length === 0) {
            mediaBox.classList.add('hidden');
            return;
        }

        const withMedia = sources.filter(s => s && (s.image || s.file || s.file_two || s.url));
        if (withMedia.length === 0) {
            mediaBox.classList.add('hidden');
            return;
        }

        const dbSources = withMedia.filter(s => !s.is_files2);
        const freeFiles = withMedia.filter(s => Boolean(s.is_files2));

        if (dbSources.length > 0) {
            const heading = document.createElement('div');
            heading.className = 'text-xs font-bold uppercase tracking-wider text-gray-600';
            heading.textContent = 'Matched Files, Images & Links';
            mediaBox.appendChild(heading);

            dbSources.forEach(item => {
                const row = document.createElement('div');
                row.className = 'border-2 border-red-500 rounded-lg p-3 space-y-2 text-sm';

                const titleEl = document.createElement('div');
                titleEl.className = 'font-semibold text-gray-900';
                titleEl.textContent = item.title || 'Matched Record';
                row.appendChild(titleEl);

                if (item.image) {
                    const img = document.createElement('img');
                    img.src = toAssetUrl(item.image);
                    img.alt = item.title || 'Attached image';
                    img.className = 'max-h-48 rounded border border-gray-200 object-contain';
                    row.appendChild(img);
                }

                const linksRow = document.createElement('div');
                linksRow.className = 'flex flex-wrap gap-3';

                if (item.file) {
                    const fLink = document.createElement('a');
                    fLink.href = toAssetUrl(item.file);
                    fLink.target = '_blank';
                    fLink.rel = 'noopener noreferrer';
                    fLink.className = 'inline-flex items-center bg-gray-100 hover:bg-gray-200 text-gray-800 border border-gray-300 rounded px-2.5 py-1 text-xs font-medium';
                    fLink.textContent = 'File: ' + String(item.file).split('/').pop();
                    linksRow.appendChild(fLink);
                }

                if (item.file_two) {
                    const f2Link = document.createElement('a');
                    f2Link.href = toAssetUrl(item.file_two);
                    f2Link.target = '_blank';
                    f2Link.rel = 'noopener noreferrer';
                    f2Link.className = 'inline-flex items-center bg-gray-100 hover:bg-gray-200 text-gray-800 border border-gray-300 rounded px-2.5 py-1 text-xs font-medium';
                    f2Link.textContent = 'File 2: ' + String(item.file_two).split('/').pop();
                    linksRow.appendChild(f2Link);
                }

                if (item.url && /^https?:\/\//i.test(item.url)) {
                    const uLink = document.createElement('a');
                    uLink.href = item.url;
                    uLink.target = '_blank';
                    uLink.rel = 'noopener noreferrer';
                    uLink.className = 'text-blue-600 hover:underline text-xs';
                    uLink.textContent = item.url;
                    linksRow.appendChild(uLink);
                }

                if (linksRow.childNodes.length > 0) {
                    row.appendChild(linksRow);
                }

                mediaBox.appendChild(row);
            });
        }

        if (freeFiles.length > 0) {
            const freeSection = document.createElement('div');
            freeSection.className = 'border-2 border-blue-500 rounded-lg p-3 space-y-2';

            const freeHeading = document.createElement('h1');
            freeHeading.className = 'text-xl font-bold text-blue-700';
            freeHeading.textContent = 'Free Files (files2)';
            freeSection.appendChild(freeHeading);

            freeFiles.forEach(item => {
                const row = document.createElement('div');
                row.className = 'flex items-center justify-between gap-3 border border-blue-300 rounded-lg px-3 py-2 text-sm';

                const titleEl = document.createElement('span');
                titleEl.className = 'font-semibold text-gray-900';
                titleEl.textContent = item.title || String(item.file || item.image || '').split('/').pop();
                row.appendChild(titleEl);

                const assetPath = item.file || item.image;
                if (assetPath) {
                    const link = document.createElement('a');
                    link.href = toAssetUrl(assetPath);
                    link.target = '_blank';
                    link.rel = 'noopener noreferrer';
                    link.className = 'text-blue-600 hover:underline text-xs font-medium';
                    link.textContent = 'Open File';
                    row.appendChild(link);
                }

                freeSection.appendChild(row);
            });

            mediaBox.appendChild(freeSection);
        }

        mediaBox.classList.remove('hidden');
    }

    function appendBubble(role, text, id = null, badgeText = null) {
        const emptyEl = document.getElementById('chat-empty-state');
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
        messagesBox.appendChild(wrapper);
        messagesBox.scrollTop = messagesBox.scrollHeight;
        return wrapper;
    }

    function renderAllMessages(list) {
        messagesBox.innerHTML = '';
        if (!Array.isArray(list) || list.length === 0) {
            messagesBox.innerHTML = '<div id="chat-empty-state" class="text-center text-gray-500 py-8">Ask a question about your stored content to start a conversation.</div>';
            return;
        }
        list.forEach(item => appendBubble(item.role, item.text));
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
        appendBubble('assistant', 'Thinking...', 'chat-pending-bubble', chosenModel || null);

        try {
            const payload = { message: text };
            if (chosenModel) {
                payload.model = chosenModel;
            }

            const data = await postJson(payload);
            removePendingBubble();

            if (Array.isArray(data.messages) && data.messages.length > 0) {
                renderAllMessages(data.messages);
            } else if (data.reply) {
                appendBubble('assistant', data.reply, null, data.modelUsed || chosenModel || null);
            } else {
                showError('Empty response received from server.');
            }

            const mergedSources = [
                ...(Array.isArray(data.sources) ? data.sources : []),
                ...(Array.isArray(data.standaloneFiles) ? data.standaloneFiles.map(sf => ({
                    title: sf.filename || String(sf.relative_path || '').split('/').pop(),
                    file: sf.relative_path || null,
                    is_files2: true,
                })) : []),
            ];
            renderMediaAttachments(mergedSources);


        } catch (err) {
            removePendingBubble();
            if (err.name === 'AbortError') {
                showError('Request timed out after 45s. Try switching to another model in the dropdown.');
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
            renderMediaAttachments([]);
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
