<?php

namespace App\Service;

use App\Interface\ContentRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Throwable;
use ZipArchive;

class ContentSearchService
{
    protected ContentRepositoryInterface $repo;

    public function __construct(ContentRepositoryInterface $repo)
    {
        $this->repo = $repo;
    }

    /**
     * Execute multimodal RAG search: retrieve candidate records across text, URLs, file paths,
     * and stored file contents (including PDFs), and synthesize an answer with Gemini.
     *
     * @return array{results: Collection, aiAnswer: ?string, matchedFiles: array<int, array<string, mixed>>}
     */
    public function searchWithRag(
        string $query,
        int $maxContextItems = 5,
        ?string $model = null,
        float $temperature = 0.2,
        bool $includeMedia = true,
        int $maxInlineBytes = 10485760
    ): array {
        $cleanQuery = trim($query);
        $terms = preg_split('/\s+/u', $cleanQuery, -1, PREG_SPLIT_NO_EMPTY) ?: [$cleanQuery];
        $searchTerms = array_values(array_unique(array_filter(array_merge([$cleanQuery], $terms))));

        $collected = collect();

        foreach ($searchTerms as $term) {
            $searchFields = [
                'title'      => $term,
                'content'    => $term,
                'keywords'   => $term,
                'url_one'    => $term,
                'url_two'    => $term,
                'url_three'  => $term,
                'file_path'  => $term,
                'file_path_two' => $term,
                'image_path' => $term,
                
            ];

            try {
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
            } catch (Throwable) {
                $fallbackMatches = $this->repo->ReturnSearchMany(
                    filters: [],
                    orderBy: 'id',
                    orderDir: 'desc',
                    related: [],
                    fields: ['*'],
                    searchFields: [
                        'title'    => $term,
                        'content'  => $term,
                        'keywords' => $term,
                    ],
                    boolean: 'OR',
                    operator: 'LIKE'
                );

                $collected = $collected->merge($fallbackMatches);
            }
        }

        // Deduplicate by primary key while keeping order
        $results = $collected->unique('id')->values();

        // Also scan stored files (PDFs, docs, text files) for matching query terms
        $matchedFiles = $this->findMatchingStoredFiles($searchTerms, $maxInlineBytes);

        // If a matched file corresponds to an existing DB row's file_path/pdf_path, ensure it's included
        if (!empty($matchedFiles)) {
            $matchedRelPaths = array_column($matchedFiles, 'relative_path');
            $extraRows = $this->findRowsByFilePaths($matchedRelPaths);
            if ($extraRows->isNotEmpty()) {
                $results = $extraRows->concat($results)->unique('id')->values();
            }
        }

        $contextItems = $results->take($maxContextItems);
        $aiAnswer = $this->generateGeminiAnswer(
            $cleanQuery,
            $contextItems,
            $matchedFiles,
            $model,
            $temperature,
            $includeMedia,
            $maxInlineBytes
        );

        return [
            'results'      => $results,
            'aiAnswer'     => $aiAnswer,
            'matchedFiles' => $matchedFiles,
        ];
    }

    /**
     * Find DB rows whose file_path or pdf_path matches any of the discovered file paths.
     */
    protected function findRowsByFilePaths(array $relativePaths): Collection
    {
        if (empty($relativePaths)) {
            return collect();
        }

        $found = collect();
        foreach ($relativePaths as $relPath) {
            foreach (['file_path','file_path_two'] as $col) {
                try {
                    $rows = $this->repo->ReturnSearchMany(
                        filters: [],
                        orderBy: 'id',
                        orderDir: 'desc',
                        related: [],
                        fields: ['*'],
                        searchFields: [$col => $relPath],
                        boolean: 'OR',
                        operator: 'LIKE'
                    );
                    $found = $found->merge($rows);
                } catch (Throwable) {
                    // Ignore if column doesn't exist
                }
            }
        }

        return $found->unique('id')->values();
    }

    /**
     * Scan stored files under upload/storage directories for matching query terms in filename or extracted text.
     *
     * @param  array<int, string>  $terms
     * @return array<int, array<string, mixed>>
     */
    protected function findMatchingStoredFiles(array $terms, int $maxInlineBytes): array
    {
        $meaningfulTerms = array_values(array_filter($terms, function (string $t): bool {
            $low = mb_strtolower(trim($t));
            return $low !== '' && mb_strlen($low) >= 2 && !in_array($low, ['your', 'the', 'and', 'for', 'with', 'from'], true);
        }));

        if (empty($meaningfulTerms)) {
            return [];
        }

        $candidateDirs = [
            public_path('uploads'),
            storage_path('app/public'),
        ];

        $supportedExts = ['pdf', 'docx', 'txt', 'md', 'csv', 'json', 'html', 'log'];
        $matches = [];

        foreach ($candidateDirs as $baseDir) {
            if (!File::isDirectory($baseDir)) {
                continue;
            }

            foreach (File::allFiles($baseDir) as $fileInfo) {
                $ext = strtolower($fileInfo->getExtension());
                if (!in_array($ext, $supportedExts, true)) {
                    continue;
                }

                $fullPath = $fileInfo->getPathname();
                $size = $fileInfo->getSize();
                if ($size === 0 || $size > $maxInlineBytes) {
                    continue;
                }

                $relPath = $this->toRelativeStoragePath($fullPath);
                $haystackName = mb_strtolower($fileInfo->getFilename() . ' ' . $relPath);

                $matchedTerm = null;
                foreach ($meaningfulTerms as $term) {
                    if (mb_strpos($haystackName, mb_strtolower($term)) !== false) {
                        $matchedTerm = $term;
                        break;
                    }
                }

                $extractedText = '';
                if ($matchedTerm === null) {
                    $extractedText = $this->extractReadableFileText($fullPath, $ext);
                    if ($extractedText !== '') {
                        $haystackText = mb_strtolower($extractedText);
                        foreach ($meaningfulTerms as $term) {
                            if (mb_strpos($haystackText, mb_strtolower($term)) !== false) {
                                $matchedTerm = $term;
                                break;
                            }
                        }
                    }
                }

                if ($matchedTerm !== null) {
                    $matches[] = [
                        'filename'      => $fileInfo->getFilename(),
                        'relative_path' => $relPath,
                        'full_path'     => $fullPath,
                        'extension'     => $ext,
                        'matched_term'  => $matchedTerm,
                        'excerpt'       => $extractedText !== '' ? mb_substr($extractedText, 0, 4000) : '',
                    ];

                    if (count($matches) >= 5) {
                        break 2;
                    }
                }
            }
        }

        return $matches;
    }

    /**
     * Extract text from PDF, DOCX, or plain text files (with caching).
     */
    protected function extractReadableFileText(string $fullPath, string $ext): string
    {
        $cacheKey = 'rag_file_text_' . md5($fullPath . '|' . @filemtime($fullPath));

        return (string) Cache::remember($cacheKey, now()->addMinutes(30), function () use ($fullPath, $ext) {
            try {
                if ($ext === 'docx') {
                    return $this->extractDocxText($fullPath);
                }

                if (in_array($ext, ['txt', 'md', 'csv', 'json', 'html', 'log'], true)) {
                    $raw = (string) File::get($fullPath);
                    $plain = $ext === 'html' ? strip_tags($raw) : $raw;
                    return trim(preg_replace('/\s+/u', ' ', $plain) ?? $plain);
                }

                if ($ext === 'pdf') {
                    return $this->extractPdfText($fullPath);
                }

                return '';
            } catch (Throwable) {
                return '';
            }
        });
    }

    /**
     * Best-effort PDF text extraction without requiring external binaries.
     */
    protected function extractPdfText(string $fullPath): string
    {
        $raw = (string) File::get($fullPath);
        if ($raw === '') {
            return '';
        }

        $chunks = [];

        // Extract literal text strings inside parentheses in PDF content streams: (Some text)
        if (preg_match_all('/\(([^()\\\\]*(?:\\\\.[^()\\\\]*)*)\)\s*T[jJ]/s', $raw, $matches)) {
            foreach ($matches[1] as $token) {
                $decoded = stripcslashes($token);
                if (preg_match('/[A-Za-z0-9\p{L}]/u', $decoded)) {
                    $chunks[] = $decoded;
                }
            }
        }

        // Also scan any readable ASCII/UTF-8 runs for keywords like html5, css3, etc.
        $text = implode(' ', $chunks);
        if (mb_strlen(trim($text)) < 20) {
            $cleaned = preg_replace('/[^\x20-\x7E\r\n]+/', ' ', $raw) ?? '';
            $cleaned = preg_replace('/\s+/', ' ', $cleaned) ?? '';
            $text .= ' ' . mb_substr($cleaned, 0, 20000);
        }

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    protected function toRelativeStoragePath(string $fullPath): string
    {
        $normFull = str_replace('\\', '/', $fullPath);
        $normPublic = str_replace('\\', '/', public_path());
        $normStoragePublic = str_replace('\\', '/', storage_path('app/public'));

        if (str_starts_with($normFull, $normPublic . '/')) {
            return ltrim(substr($normFull, strlen($normPublic)), '/');
        }

        if (str_starts_with($normFull, $normStoragePublic . '/')) {
            return ltrim(substr($normFull, strlen($normStoragePublic)), '/');
        }

        return basename($normFull);
    }

    /**
     * Build multimodal parts (text + URL content + inline_data for images/PDFs + extracted doc text)
     * and call Gemini REST API.
     */
    protected function generateGeminiAnswer(
        string $query,
        Collection $contextItems,
        array $matchedFiles,
        ?string $model,
        float $temperature,
        bool $includeMedia,
        int $maxInlineBytes
    ): ?string {
        $apiKey = config('central.gemini_key');
        $activeModel = $model ?: config('central.default_model', 'gemini-2.5-flash');

        if (empty($apiKey)) {
            return 'Gemini API key is not configured in config/central.php.';
        }

        if ($contextItems->isEmpty() && empty($matchedFiles)) {
            return 'No matching records or files were found in the knowledge base to ground a RAG response.';
        }

        $parts = [
            [
                'text' => "You are a multimodal retrieval-augmented assistant. Answer the user's question ONLY using the provided context documents, fetched URL contents, and attached files/images.\n"
                    . "If the provided context does not contain enough information, state that clearly.\n\n"
                    . "=== RETRIEVED CONTEXT DOCUMENTS ===",
            ],
        ];

        $attachedPaths = [];

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

            $urls = array_values(array_filter([
                $item->url_one ?? null,
                $item->url_two ?? null,
                $item->url_three ?? null,
            ]));

            if (!empty($urls)) {
                $lines[] = "URLs: " . implode(', ', $urls);
            }

            foreach (['file_path', 'file_path_two','image_path'] as $pathCol) {
                if (!empty($item->{$pathCol})) {
                    $lines[] = "{$pathCol}: {$item->{$pathCol}}";
                }
            }

            foreach ($urls as $url) {
                $fetchedText = $this->fetchUrlExcerpt($url);
                if ($fetchedText !== '') {
                    $lines[] = "Fetched URL Content ({$url}):\n{$fetchedText}";
                }
            }

            $parts[] = ['text' => implode("\n", $lines)];

            if ($includeMedia) {
                foreach (['image_path', 'file_path', 'file_path_two'] as $mediaCol) {
                    $mediaPath = $item->{$mediaCol} ?? null;
                    if (!empty($mediaPath) && !in_array((string) $mediaPath, $attachedPaths, true)) {
                        $attachedPaths[] = (string) $mediaPath;
                        $this->appendAttachmentParts(
                            $parts,
                            (string) $mediaPath,
                            "Document #{$num} ({$mediaCol})",
                            $maxInlineBytes
                        );
                    }
                }
            }
        }

        foreach ($matchedFiles as $fileIdx => $mf) {
            $fNum = $fileIdx + 1;
            $relPath = (string) $mf['relative_path'];
            $desc = "\n--- Matched Stored File #{$fNum}: {$mf['filename']} ({$relPath}) ---";
            if (!empty($mf['excerpt'])) {
                $desc .= "\nExtracted Text Excerpt:\n{$mf['excerpt']}";
            }
            $parts[] = ['text' => $desc];

            if ($includeMedia && !in_array($relPath, $attachedPaths, true)) {
                $attachedPaths[] = $relPath;
                $this->appendAttachmentParts(
                    $parts,
                    $relPath,
                    "Matched File #{$fNum}",
                    $maxInlineBytes
                );
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
     * Fetch and cache plain-text excerpt from a URL safely with a short timeout.
     */
    protected function fetchUrlExcerpt(string $url): string
    {
        $cleanUrl = trim($url);
        if (!preg_match('#^https?://#i', $cleanUrl)) {
            return '';
        }

        $cacheKey = 'rag_url_excerpt_' . md5($cleanUrl);

        return (string) Cache::remember($cacheKey, now()->addMinutes(30), function () use ($cleanUrl) {
            try {
                $res = Http::timeout(4)
                    ->withHeaders(['User-Agent' => 'LaravelRAGBot/1.0'])
                    ->get($cleanUrl);

                if (!$res->successful()) {
                    return '';
                }

                $body = (string) $res->body();
                if ($body === '') {
                    return '';
                }

                $withoutScripts = preg_replace('#<(script|style)[^>]*>.*?</\1>#is', ' ', $body) ?? $body;
                $plain = html_entity_decode(strip_tags($withoutScripts), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $normalized = trim(preg_replace('/\s+/u', ' ', $plain) ?? $plain);

                return mb_substr($normalized, 0, 2500);
            } catch (Throwable) {
                return '';
            }
        });
    }

    /**
     * Resolve a stored relative path (public/ or storage/app/public/) and append inline_data or extracted text.
     */
    protected function appendAttachmentParts(array &$parts, string $relativePath, string $label, int $maxInlineBytes): void
    {
        $fullPath = $this->resolveStoredFilePath($relativePath);
        if (!$fullPath || !File::isFile($fullPath)) {
            return;
        }

        $size = File::size($fullPath);
        if ($size === 0 || $size > $maxInlineBytes) {
            return;
        }

        $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        $mimeType = File::mimeType($fullPath) ?: 'application/octet-stream';

        if ($ext === 'docx') {
            $docxText = $this->extractDocxText($fullPath);
            if ($docxText !== '') {
                $parts[] = [
                    'text' => "[{$label} ({$relativePath}) DOCX Content]:\n" . mb_substr($docxText, 0, 50000),
                ];
                return;
            }
        }

        if (
            in_array($ext, ['txt', 'csv', 'json', 'md', 'log', 'xml', 'html'], true)
            || in_array($mimeType, ['text/plain', 'text/html', 'application/json', 'text/csv', 'text/markdown'], true)
        ) {
            $rawText = File::get($fullPath);
            $parts[] = [
                'text' => "[{$label} ({$relativePath})]:\n" . mb_substr($rawText, 0, 50000),
            ];
            return;
        }

        $supportedInlineMimes = [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/gif',
        ];

        if ($ext === 'pdf' && $mimeType === 'application/octet-stream') {
            $mimeType = 'application/pdf';
        }

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
     * Extract text from word/document.xml inside a .docx archive.
     */
    protected function extractDocxText(string $fullPath): string
    {
        if (!class_exists(ZipArchive::class)) {
            return '';
        }

        $zip = new ZipArchive();
        if ($zip->open($fullPath) !== true) {
            return '';
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if (!is_string($xml) || $xml === '') {
            return '';
        }

        $plain = html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $plain) ?? $plain);
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