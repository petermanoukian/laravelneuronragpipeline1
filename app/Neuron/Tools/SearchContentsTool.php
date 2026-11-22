<?php

namespace App\Neuron\Tools;

use App\Interface\ContentRepositoryInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Throwable;
use ZipArchive;

class SearchContentsTool extends Tool
{
    protected ContentRepositoryInterface $repo;

    /**
     * Captured media/source items from the latest tool invocation.
     *
     * @var array<int, array{id: int|string|null, title: string, url: ?string, file: ?string, image: ?string}>
     */
    protected static array $capturedSources = [];

    public function __construct(ContentRepositoryInterface $repo)
    {
        $this->repo = $repo;
        $this->name = 'search_contents';
        $this->description = 'Search stored knowledge base content entries, URLs, and attached files (PDF, Word, text) by keyword or topic.';
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'query',
                type: PropertyType::STRING,
                description: 'Search keyword or phrase to look up across content records, URLs, and stored files.',
                required: true
            ),
        ];
    }

    public static function resetCapturedSources(): void
    {
        static::$capturedSources = [];
    }

    /**
     * @return array<int, array{id: int|string|null, title: string, url: ?string, file: ?string, image: ?string}>
     */
    public static function getCapturedSources(): array
    {
        return array_values(static::$capturedSources);
    }

    public function __invoke(string $query): string
    {
        $cleanQuery = trim($query);
        if ($cleanQuery === '') {
            return 'No matching records found in contents.';
        }

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
                'image_path' => $term,
                'pdf_path'   => $term,
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
                $fallback = $this->repo->ReturnSearchMany(
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
                $collected = $collected->merge($fallback);
            }
        }

        $items = $collected->unique('id')->take(8)->values();
        $matchedFiles = $this->scanStoredFilesForTerms($searchTerms);

        if ($items->isEmpty() && empty($matchedFiles)) {
            return 'No matching records or files found in contents.';
        }

        $blocks = [];

        foreach ($items as $item) {
            $urls = array_values(array_filter([
                $item->url_one ?? null,
                $item->url_two ?? null,
                $item->url_three ?? null,
            ]));
            $file = $item->file_path ?? $item->pdf_path ?? null;
            $image = $item->image_path ?? null;

            $sourceKey = 'row:' . ($item->id ?? md5((string) ($item->title ?? '')));
            static::$capturedSources[$sourceKey] = [
                'id'    => $item->id ?? null,
                'title' => (string) ($item->title ?? 'Untitled Record'),
                'url'   => $urls[0] ?? null,
                'file'  => is_string($file) && $file !== '' ? ltrim($file, '/') : null,
                'image' => is_string($image) && $image !== '' ? ltrim($image, '/') : null,
            ];

            $lines = [
                "ID: {$item->id}",
                "Title: {$item->title}",
                "Keywords: " . ($item->keywords ?? ''),
                "Content: {$item->content}",
            ];

            if (!empty($urls)) {
                $lines[] = "URLs: " . implode(', ', $urls);
                foreach ($urls as $u) {
                    $excerpt = $this->fetchUrlExcerpt((string) $u);
                    if ($excerpt !== '') {
                        $lines[] = "URL Content ({$u}): {$excerpt}";
                    }
                }
            }

            if (!empty($item->file_path)) {
                $lines[] = "File: {$item->file_path}";
                $docText = $this->extractStoredFileText((string) $item->file_path);
                if ($docText !== '') {
                    $lines[] = "Extracted File Text: " . mb_substr($docText, 0, 2500);
                }
            }

            if (!empty($item->pdf_path)) {
                $lines[] = "PDF: {$item->pdf_path}";
                $pdfText = $this->extractStoredFileText((string) $item->pdf_path);
                if ($pdfText !== '') {
                    $lines[] = "Extracted PDF Text: " . mb_substr($pdfText, 0, 2500);
                }
            }

            if (!empty($item->image_path)) {
                $lines[] = "Image: {$item->image_path}";
            }

            $blocks[] = implode("\n", $lines);
        }

        foreach ($matchedFiles as $mf) {
            $rel = ltrim((string) $mf['relative_path'], '/');
            $ext = strtolower((string) ($mf['extension'] ?? ''));
            $isImg = in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true);

            $sourceKey = 'file:' . md5($rel);
            static::$capturedSources[$sourceKey] = [
                'id'    => null,
                'title' => (string) $mf['filename'],
                'url'   => null,
                'file'  => $isImg ? null : $rel,
                'image' => $isImg ? $rel : null,
            ];

            $fLines = [
                "Matched File: {$mf['filename']} ({$rel})",
            ];
            if (!empty($mf['excerpt'])) {
                $fLines[] = "Extracted File Text: " . mb_substr((string) $mf['excerpt'], 0, 2500);
            }
            $blocks[] = implode("\n", $fLines);
        }

        return implode("\n---\n", $blocks);
    }

    /**
     * @param  array<int, string>  $terms
     * @return array<int, array<string, string>>
     */
    protected function scanStoredFilesForTerms(array $terms): array
    {
        $meaningful = array_values(array_filter($terms, function (string $t): bool {
            $low = mb_strtolower(trim($t));
            return $low !== '' && mb_strlen($low) >= 2 && !in_array($low, ['your', 'the', 'and', 'for', 'with', 'from'], true);
        }));

        if (empty($meaningful)) {
            return [];
        }

        $dirs = [
            public_path('uploads'),
            storage_path('app/public'),
        ];

        $exts = ['pdf', 'docx', 'txt', 'md', 'csv', 'json', 'html', 'log', 'jpg', 'jpeg', 'png', 'webp', 'gif'];
        $found = [];

        foreach ($dirs as $dir) {
            if (!File::isDirectory($dir)) {
                continue;
            }

            foreach (File::allFiles($dir) as $fileInfo) {
                $ext = strtolower($fileInfo->getExtension());
                if (!in_array($ext, $exts, true)) {
                    continue;
                }
                if ($fileInfo->getSize() === 0 || $fileInfo->getSize() > 10 * 1024 * 1024) {
                    continue;
                }

                $fullPath = $fileInfo->getPathname();
                $relPath = $this->toRelativePath($fullPath);
                $nameHaystack = mb_strtolower($fileInfo->getFilename() . ' ' . $relPath);

                $matched = false;
                foreach ($meaningful as $term) {
                    if (mb_strpos($nameHaystack, mb_strtolower($term)) !== false) {
                        $matched = true;
                        break;
                    }
                }

                $excerpt = '';
                if (!$matched && !in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                    $excerpt = $this->extractFileTextByPath($fullPath, $ext);
                    if ($excerpt !== '') {
                        $textHaystack = mb_strtolower($excerpt);
                        foreach ($meaningful as $term) {
                            if (mb_strpos($textHaystack, mb_strtolower($term)) !== false) {
                                $matched = true;
                                break;
                            }
                        }
                    }
                }

                if ($matched) {
                    $found[] = [
                        'filename'      => $fileInfo->getFilename(),
                        'relative_path' => $relPath,
                        'extension'     => $ext,
                        'excerpt'       => $excerpt,
                    ];

                    if (count($found) >= 5) {
                        break 2;
                    }
                }
            }
        }

        return $found;
    }

    protected function extractStoredFileText(string $relativePath): string
    {
        $fullPath = $this->resolveStoredFilePath($relativePath);
        if (!$fullPath || !File::isFile($fullPath)) {
            return '';
        }

        $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        return $this->extractFileTextByPath($fullPath, $ext);
    }

    protected function extractFileTextByPath(string $fullPath, string $ext): string
    {
        $cacheKey = 'chat_v1_file_text_' . md5($fullPath . '|' . @filemtime($fullPath));

        return (string) Cache::remember($cacheKey, now()->addMinutes(30), function () use ($fullPath, $ext) {
            try {
                if ($ext === 'docx') {
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

                if (in_array($ext, ['txt', 'md', 'csv', 'json', 'html', 'log'], true)) {
                    $raw = (string) File::get($fullPath);
                    $plain = $ext === 'html' ? strip_tags($raw) : $raw;
                    return trim(preg_replace('/\s+/u', ' ', $plain) ?? $plain);
                }

                if ($ext === 'pdf') {
                    $raw = (string) File::get($fullPath);
                    $chunks = [];
                    if (preg_match_all('/\(([^()\\\\]*(?:\\\\.[^()\\\\]*)*)\)\s*T[jJ]/s', $raw, $matches)) {
                        foreach ($matches[1] as $token) {
                            $decoded = stripcslashes($token);
                            if (preg_match('/[A-Za-z0-9\p{L}]/u', $decoded)) {
                                $chunks[] = $decoded;
                            }
                        }
                    }
                    $text = implode(' ', $chunks);
                    if (mb_strlen(trim($text)) < 20) {
                        $cleaned = preg_replace('/[^\x20-\x7E\r\n]+/', ' ', $raw) ?? '';
                        $text .= ' ' . (preg_replace('/\s+/', ' ', $cleaned) ?? '');
                    }
                    return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
                }

                return '';
            } catch (Throwable) {
                return '';
            }
        });
    }

    protected function fetchUrlExcerpt(string $url): string
    {
        $cleanUrl = trim($url);
        if (!preg_match('#^https?://#i', $cleanUrl)) {
            return '';
        }

        $cacheKey = 'chat_v1_url_' . md5($cleanUrl);

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

                return mb_substr($normalized, 0, 2000);
            } catch (Throwable) {
                return '';
            }
        });
    }

    protected function resolveStoredFilePath(string $relativePath): ?string
    {
        $clean = ltrim($relativePath, '/');
        foreach ([public_path($clean), storage_path('app/public/' . $clean)] as $candidate) {
            if (File::exists($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    protected function toRelativePath(string $fullPath): string
    {
        $normFull = str_replace('\\', '/', $fullPath);
        $normPublic = str_replace('\\', '/', public_path());
        $normStorage = str_replace('\\', '/', storage_path('app/public'));

        if (str_starts_with($normFull, $normPublic . '/')) {
            return ltrim(substr($normFull, strlen($normPublic)), '/');
        }
        if (str_starts_with($normFull, $normStorage . '/')) {
            return ltrim(substr($normFull, strlen($normStorage)), '/');
        }
        return basename($normFull);
    }
}
