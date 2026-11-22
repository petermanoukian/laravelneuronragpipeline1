<?php

namespace App\Neuron\Tools;

use App\Interface\ContentRepositoryInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Throwable;
use ZipArchive;

class CachedSearchContentsTool extends Tool
{
    protected ContentRepositoryInterface $contentRepo;

    /**
     * Captured source records from the most recent tool invocation(s).
     *
     * @var array<int, array{id: int|string|null, title: string, url: ?string, file: ?string, image: ?string}>
     */
    protected static array $capturedSources = [];

    public function __construct(ContentRepositoryInterface $contentRepo)
    {
        $this->contentRepo = $contentRepo;

        $this->name = 'search_contents_cached';
        $this->description = 'Search stored knowledge base content entries, URLs, and attached files (PDF, Word, text) by keyword or topic.';

        $this->addProperty(
            new ToolProperty(
                name: 'query',
                type: PropertyType::STRING,
                description: 'Search keyword or phrase to look up across content records, URLs, and stored files.',
                required: true
            )
        );

        if (method_exists($this, 'setCallable')) {
            $this->setCallable($this);
        }
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
        $cleanQuery = $this->scrubUtf8(trim($query));
        if ($cleanQuery === '') {
            return 'Empty search query provided.';
        }

        $cacheKey = 'neuron_rag_search_v5_' . md5(mb_strtolower($cleanQuery));

        $cachedPayload = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($cleanQuery) {
            $results = $this->runTargetedSearch($cleanQuery);

            $records = [];
            foreach ($results as $item) {
                $id = data_get($item, 'id');
                $title = $this->scrubUtf8((string) (data_get($item, 'title') ?? data_get($item, 'name') ?? 'Untitled Record'));
                $keywords = $this->scrubUtf8((string) (data_get($item, 'keywords') ?? ''));
                $description = $this->scrubUtf8((string) (data_get($item, 'content') ?? data_get($item, 'description') ?? data_get($item, 'body') ?? ''));

                $urls = array_values(array_filter([
                    data_get($item, 'url_one'),
                    data_get($item, 'url_two'),
                    data_get($item, 'url_three'),
                    data_get($item, 'url'),
                    data_get($item, 'link'),
                ], fn ($u) => is_string($u) && trim($u) !== ''));
                $urls = array_map(fn ($u) => $this->scrubUtf8(trim((string) $u)), $urls);

                $fileRaw = data_get($item, 'file_path') ?? data_get($item, 'pdf_path') ?? data_get($item, 'file');
                $imageRaw = data_get($item, 'image_path') ?? data_get($item, 'image');

                $file = is_string($fileRaw) && trim($fileRaw) !== '' ? $this->scrubUtf8(ltrim(trim($fileRaw), '/')) : null;
                $image = is_string($imageRaw) && trim($imageRaw) !== '' ? $this->scrubUtf8(ltrim(trim($imageRaw), '/')) : null;
                $primaryUrl = $urls[0] ?? null;

                $urlExcerpts = [];
                foreach ($urls as $u) {
                    $excerpt = $this->fetchUrlExcerpt((string) $u);
                    if ($excerpt !== '') {
                        $urlExcerpts[(string) $u] = $this->scrubUtf8($excerpt);
                    }
                }

                $extractedFileText = '';
                if ($file !== null) {
                    $extractedFileText = $this->extractStoredFileText($file);
                } elseif ($image !== null) {
                    $extractedFileText = $this->extractStoredFileText($image);
                }

                $dedupKey = $id !== null ? 'id:' . $id : 'title:' . md5($title);
                if (isset($records[$dedupKey])) {
                    continue;
                }

                $records[$dedupKey] = [
                    'id'           => $id,
                    'title'        => $title,
                    'keywords'     => $keywords,
                    'description'  => mb_substr(strip_tags($description), 0, 400),
                    'url'          => $primaryUrl,
                    'urls'         => $urls,
                    'url_excerpts' => $urlExcerpts,
                    'file'         => $file,
                    'image'        => $image,
                    'file_text'    => $extractedFileText !== '' ? $this->scrubUtf8(mb_substr($extractedFileText, 0, 2500)) : '',
                ];

                if (count($records) >= 6) {
                    break;
                }
            }

            $terms = preg_split('/[^\p{L}\p{N}_-]+/u', mb_strtolower($cleanQuery)) ?: [];
            $matchedFiles = $this->scanStoredFilesForTerms($terms);

            foreach ($matchedFiles as $mf) {
                $rel = $this->scrubUtf8(ltrim((string) $mf['relative_path'], '/'));
                $ext = strtolower((string) ($mf['extension'] ?? ''));
                $isImg = in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true);
                $dedupKey = 'file:' . md5($rel);

                if (isset($records[$dedupKey])) {
                    continue;
                }

                $records[$dedupKey] = [
                    'id'           => null,
                    'title'        => $this->scrubUtf8((string) $mf['filename']),
                    'keywords'     => '',
                    'description'  => "Matched file: {$rel}",
                    'url'          => null,
                    'urls'         => [],
                    'url_excerpts' => [],
                    'file'         => $isImg ? null : $rel,
                    'image'        => $isImg ? $rel : null,
                    'file_text'    => $this->scrubUtf8((string) ($mf['excerpt'] ?? '')),
                ];

                if (count($records) >= 8) {
                    break;
                }
            }

            Log::info('CachedSearchContentsTool matched records', [
                'query' => $cleanQuery,
                'count' => count($records),
                'ids'   => array_map(fn ($r) => $r['id'], $records),
            ]);

            return array_values($records);
        });

        foreach ($cachedPayload as $rec) {
            $sourceKey = $rec['id'] !== null
                ? 'id:' . $rec['id']
                : ('item:' . md5($rec['title'] . '|' . ($rec['file'] ?? '') . '|' . ($rec['image'] ?? '') . '|' . ($rec['url'] ?? '')));

            static::$capturedSources[$sourceKey] = [
                'id'    => $rec['id'],
                'title' => $this->scrubUtf8((string) $rec['title']),
                'url'   => $rec['url'] !== null ? $this->scrubUtf8((string) $rec['url']) : null,
                'file'  => $rec['file'] !== null ? $this->scrubUtf8((string) $rec['file']) : null,
                'image' => $rec['image'] !== null ? $this->scrubUtf8((string) $rec['image']) : null,
            ];
        }

        if (empty($cachedPayload)) {
            return $this->scrubUtf8("No matching content records or files found for query: \"{$cleanQuery}\".");
        }

        $lines = [$this->scrubUtf8("Matching content records for \"{$cleanQuery}\":")];
        foreach ($cachedPayload as $rec) {
            $idLabel = $rec['id'] !== null ? "ID {$rec['id']}" : 'File Match';
            $line = "- [{$idLabel}] {$rec['title']}";
            if (!empty($rec['keywords'])) {
                $line .= " | Keywords: {$rec['keywords']}";
            }
            if ($rec['description'] !== '') {
                $line .= ": {$rec['description']}";
            }
            if (!empty($rec['urls'])) {
                $line .= " | URLs: " . implode(', ', $rec['urls']);
            }
            if (!empty($rec['file'])) {
                $line .= " | File: {$rec['file']}";
            }
            if (!empty($rec['image'])) {
                $line .= " | Image: {$rec['image']}";
            }
            if (!empty($rec['url_excerpts'])) {
                foreach ($rec['url_excerpts'] as $u => $ex) {
                    $line .= "\n  URL Content ({$u}): {$ex}";
                }
            }
            if (!empty($rec['file_text'])) {
                $line .= "\n  Extracted File Text: {$rec['file_text']}";
            }
            $lines[] = $this->scrubUtf8($line);
        }

        return $this->scrubUtf8(implode("\n", $lines));
    }

    /**
     * @return iterable<int, mixed>
     */
    protected function runTargetedSearch(string $cleanQuery): iterable
    {
        $collected = [];
        $seenKeys = [];

        $addItems = function (iterable $items) use (&$collected, &$seenKeys): void {
            foreach ($items as $item) {
                $id = data_get($item, 'id');
                $title = (string) (data_get($item, 'title') ?? spl_object_id((object) (array) $item));
                $key = $id !== null ? 'id:' . $id : 'k:' . md5($title);
                if (!isset($seenKeys[$key])) {
                    $seenKeys[$key] = true;
                    $collected[] = $item;
                }
                if (count($collected) >= 8) {
                    return;
                }
            }
        };

        $searchByTerm = function (string $term) use ($addItems): void {
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
                $matches = $this->contentRepo->ReturnSearchMany(
                    filters: [],
                    orderBy: 'id',
                    orderDir: 'desc',
                    related: [],
                    fields: ['*'],
                    searchFields: $searchFields,
                    boolean: 'OR',
                    operator: 'LIKE'
                );

                $addItems($matches);
            } catch (Throwable) {
                $fallback = $this->contentRepo->ReturnSearchMany(
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

                $addItems($fallback);
            }
        };

        $stopWords = [
            'the', 'and', 'for', 'are', 'but', 'not', 'you', 'all', 'can', 'her',
            'was', 'one', 'our', 'out', 'day', 'get', 'has', 'him', 'his', 'how',
            'man', 'new', 'now', 'old', 'see', 'two', 'way', 'who', 'boy', 'did',
            'its', 'let', 'put', 'say', 'she', 'too', 'use', 'what', 'which', 'when',
            'where', 'with', 'from', 'have', 'that', 'this', 'they', 'them', 'your',
            'about', 'into', 'more', 'some', 'such', 'than', 'then', 'there', 'these',
            'would', 'could', 'should',
        ];

        $tokens = preg_split('/[^\p{L}\p{N}_-]+/u', mb_strtolower($cleanQuery)) ?: [];
        $keywords = [];

        foreach ($tokens as $token) {
            if (mb_strlen($token) < 2) {
                continue;
            }
            if (in_array($token, $stopWords, true)) {
                continue;
            }
            if (!in_array($token, $keywords, true)) {
                $keywords[] = $token;
            }
            if (count($keywords) >= 4) {
                break;
            }
        }

        if (in_array('company', $keywords, true) || in_array('profile', $keywords, true)) {
            foreach (['about', 'company', 'services'] as $extra) {
                if (!in_array($extra, $keywords, true)) {
                    $keywords[] = $extra;
                }
            }
        }

        if (empty($keywords)) {
            $searchByTerm($cleanQuery);
            return $collected;
        }

        foreach ($keywords as $word) {
            $searchByTerm($word);
            if (count($collected) >= 6) {
                break;
            }
        }

        return $collected;
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
                $nameHaystack = $this->scrubUtf8(mb_strtolower($fileInfo->getFilename() . ' ' . $relPath));

                $matched = false;
                foreach ($meaningful as $term) {
                    if (mb_strpos($nameHaystack, $term) !== false) {
                        $matched = true;
                        break;
                    }
                }

                $excerpt = '';
                if (!$matched && !in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                    $excerpt = $this->extractFileTextByPath($fullPath, $ext);
                    if ($excerpt !== '') {
                        $textHaystack = $this->scrubUtf8(mb_strtolower($excerpt));
                        foreach ($meaningful as $term) {
                            if (mb_strpos($textHaystack, $term) !== false) {
                                $matched = true;
                                break;
                            }
                        }
                    }
                }

                if ($matched) {
                    $found[] = [
                        'filename'      => $this->scrubUtf8($fileInfo->getFilename()),
                        'relative_path' => $this->scrubUtf8($relPath),
                        'extension'     => $ext,
                        'excerpt'       => $this->scrubUtf8($excerpt),
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
        $cacheKey = 'chat_v2_file_text_v2_' . md5($fullPath . '|' . @filemtime($fullPath));

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
                    $plain = html_entity_decode(strip_tags($this->scrubUtf8($xml)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    return $this->scrubUtf8(trim(preg_replace('/\s+/u', ' ', $plain) ?? $plain));
                }

                if (in_array($ext, ['txt', 'md', 'csv', 'json', 'html', 'log'], true)) {
                    $raw = $this->scrubUtf8((string) File::get($fullPath));
                    $plain = $ext === 'html' ? strip_tags($raw) : $raw;
                    return $this->scrubUtf8(trim(preg_replace('/\s+/u', ' ', $plain) ?? $plain));
                }

                if ($ext === 'pdf') {
                    $raw = (string) File::get($fullPath);
                    $chunks = [];
                    if (preg_match_all('/\(([^()\\\\]*(?:\\\\.[^()\\\\]*)*)\)\s*T[jJ]/s', $raw, $matches)) {
                        foreach ($matches[1] as $token) {
                            $decoded = stripcslashes($token);
                            $safeToken = preg_replace('/[^\x20-\x7E\p{L}\p{N}\p{P}]/u', '', $this->scrubUtf8($decoded)) ?? '';
                            if (preg_match('/[A-Za-z0-9\p{L}]/u', $safeToken)) {
                                $chunks[] = $safeToken;
                            }
                        }
                    }
                    $text = implode(' ', $chunks);
                    if (mb_strlen(trim($text)) < 20) {
                        $cleaned = preg_replace('/[^\x20-\x7E\r\n]+/', ' ', $raw) ?? '';
                        $text .= ' ' . (preg_replace('/\s+/', ' ', $cleaned) ?? '');
                    }
                    return $this->scrubUtf8(trim(preg_replace('/\s+/u', ' ', $text) ?? $text));
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

        $cacheKey = 'chat_v2_url_v2_' . md5($cleanUrl);

        return (string) Cache::remember($cacheKey, now()->addMinutes(30), function () use ($cleanUrl) {
            try {
                $res = Http::timeout(4)
                    ->withHeaders(['User-Agent' => 'LaravelRAGBot/1.0'])
                    ->get($cleanUrl);

                if (!$res->successful()) {
                    return '';
                }

                $body = $this->scrubUtf8((string) $res->body());
                if ($body === '') {
                    return '';
                }

                $withoutScripts = preg_replace('#<(script|style)[^>]*>.*?</\1>#is', ' ', $body) ?? $body;
                $plain = html_entity_decode(strip_tags($withoutScripts), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $normalized = $this->scrubUtf8(trim(preg_replace('/\s+/u', ' ', $plain) ?? $plain));

                return mb_substr($normalized, 0, 2000);
            } catch (Throwable) {
                return '';
            }
        });
    }

    protected function scrubUtf8(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (function_exists('mb_scrub')) {
            $scrubbed = mb_scrub($value, 'UTF-8');
        } elseif (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
            $scrubbed = $converted !== false ? $converted : preg_replace('/[^\x09\x0A\x0D\x20-\x7E\x{80}-\x{10FFFF}]/u', '', $value) ?? '';
        } else {
            $scrubbed = preg_replace('/[^\x09\x0A\x0D\x20-\x7E\x{80}-\x{10FFFF}]/u', '', $value) ?? '';
        }

        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $scrubbed) ?? $scrubbed;
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