<?php

namespace App\Service;

use App\Interface\ContentRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

class ContentSearchService
{
    protected ContentRepositoryInterface $repo;

    public function __construct(ContentRepositoryInterface $repo)
    {
        $this->repo = $repo;
    }

    /**
     * Execute multimodal RAG search: retrieve candidate records via repository
     * and synthesize an answer with Gemini using text + attached files/images.
     *
     * @return array{results: Collection, aiAnswer: ?string}
     */
    public function searchWithRag(
        string $query,
        int $maxContextItems = 5,
        ?string $model = null,
        float $temperature = 0.2,
        bool $includeMedia = true,
        int $maxInlineBytes = 10485760
    ): array {
        // Split query into individual terms so multi-word queries match partial text in rows
        $terms = preg_split('/\s+/u', trim($query), -1, PREG_SPLIT_NO_EMPTY) ?: [$query];

        $collected = collect();

        foreach ($terms as $term) {
            $searchFields = [
                'title'    => $term,
                'content'  => $term,
                'keywords' => $term,
            ];

            $matches = $this->repo->ReturnSearchMany(
                filters: [],
                orderBy: 'id',
                orderDir: 'desc',
                related: [],
                fields: ['*'],
                searchFields: $searchFields,
                boolean: 'OR',
                operator: 'LIKE'
            );

            $collected = $collected->merge($matches);
        }

        // Deduplicate by primary key while keeping order
        $results = $collected->unique('id')->values();

        $contextItems = $results->take($maxContextItems);
        $aiAnswer = $this->generateGeminiAnswer(
            $query,
            $contextItems,
            $model,
            $temperature,
            $includeMedia,
            $maxInlineBytes
        );

        return [
            'results'  => $results,
            'aiAnswer' => $aiAnswer,
        ];
    }

    
    /**
     * Build multimodal parts (text + inline_data for images/PDFs) and call Gemini REST API.
     */
    protected function generateGeminiAnswer(
        string $query,
        Collection $contextItems,
        ?string $model,
        float $temperature,
        bool $includeMedia,
        int $maxInlineBytes
    ): ?string {
        $apiKey = config('central.gemini_key');
        $activeModel = $model ?: config('central.default_model', 'gemini-3-flash');

        if (empty($apiKey)) {
            return 'Gemini API key is not configured in config/central.php.';
        }

        if ($contextItems->isEmpty()) {
            return 'No matching records were found in the knowledge base to ground a RAG response.';
        }

        $parts = [
            [
                'text' => "You are a multimodal retrieval-augmented assistant. Answer the user's question ONLY using the provided context documents and attached files/images.\n"
                    . "If the provided context does not contain enough information, state that clearly.\n\n"
                    . "=== RETRIEVED CONTEXT DOCUMENTS ===",
            ],
        ];

        foreach ($contextItems as $index => $item) {
            $num = $index + 1;
            $lines = [
                "\n--- Document #{$num} (ID: {$item->id}) ---",
                "Title: {$item->title}",
            ];

            if (!empty($item->keywords)) {
                $lines[] = "Keywords: {$item->keywords}";
            }

            if (!empty($item->description)) {
                $lines[] = "Description: {$item->description}";
            }

            $lines[] = "Content: {$item->content}";

            $urls = array_filter([$item->url_one ?? null, $item->url_two ?? null, $item->url_three ?? null]);
            if (!empty($urls)) {
                $lines[] = "URLs: " . implode(', ', $urls);
            }

            $parts[] = ['text' => implode("\n", $lines)];

            if ($includeMedia) {
                $this->appendAttachmentParts($parts, $item->image_path ?? null, "Document #{$num} Image", $maxInlineBytes);
                $this->appendAttachmentParts($parts, $item->file_path ?? null, "Document #{$num} File", $maxInlineBytes);
            }
        }

        $parts[] = [
            'text' => "\n=== USER QUERY ===\n{$query}",
        ];

        $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$activeModel}:generateContent";

        $response = Http::timeout(45)
            ->withHeaders([
                'x-goog-api-key' => $apiKey,
            ])
            ->post($endpoint, [
                'contents' => [
                    [
                        'role'  => 'user',
                        'parts' => $parts,
                    ],
                ],
                'generationConfig' => [
                    'temperature' => $temperature,
                ],
            ]);

        if ($response->failed()) {
            $apiError = data_get($response->json(), 'error.message', 'Unknown API error');
            return "Gemini API request failed ({$response->status()}): {$apiError}";
        }

        return data_get($response->json(), 'candidates.0.content.parts.0.text')
            ?? 'No text response returned by Gemini.';
    }

    /**
     * Resolve a stored relative path (public/ or storage/app/public/) and append inline_data or extracted text.
     */
    protected function appendAttachmentParts(array &$parts, ?string $relativePath, string $label, int $maxInlineBytes): void
    {
        if (empty($relativePath)) {
            return;
        }

        $fullPath = $this->resolveStoredFilePath($relativePath);
        if (!$fullPath || !File::isFile($fullPath)) {
            return;
        }

        $size = File::size($fullPath);
        if ($size === 0 || $size > $maxInlineBytes) {
            return;
        }

        $mimeType = File::mimeType($fullPath) ?: 'application/octet-stream';

        // Inline text-based formats directly as text parts
        if (in_array($mimeType, ['text/plain', 'text/html', 'application/json', 'text/csv', 'text/markdown'], true)) {
            $rawText = File::get($fullPath);
            $parts[] = [
                'text' => "[{$label} ({$relativePath})]:\n" . mb_substr($rawText, 0, 50000),
            ];
            return;
        }

        // Gemini inline_data supports PDFs and standard image MIME types
        $supportedInlineMimes = [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/gif',
        ];

        if (in_array($mimeType, $supportedInlineMimes, true)) {
            $parts[] = [
                'text' => "[Attached {$label}: {$relativePath}]",
            ];
            $parts[] = [
                'inline_data' => [
                    'mime_type' => $mimeType,
                    'data'      => base64_encode(File::get($fullPath)),
                ],
            ];
        }
    }

    /**
     * Locate file whether saved under public/ (e.g., public/uploads/...) or storage/app/public/.
     */
    protected function resolveStoredFilePath(string $relativePath): ?string
    {
        $cleanPath = ltrim($relativePath, '/');

        $publicCandidate = public_path($cleanPath);
        if (File::exists($publicCandidate)) {
            return $publicCandidate;
        }

        $storageCandidate = storage_path('app/public/' . $cleanPath);
        if (File::exists($storageCandidate)) {
            return $storageCandidate;
        }

        return null;
    }
}