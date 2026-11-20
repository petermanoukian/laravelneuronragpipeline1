<?php

namespace App\Neuron\Tools;

use App\Interface\ContentRepositoryInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

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
        $this->description = 'Search stored knowledge base content entries by keyword or topic across title, content, and keywords.';

        $this->addProperty(
            new ToolProperty(
                name: 'query',
                type: PropertyType::STRING,
                description: 'Search keyword or phrase to look up in the contents table.',
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
        $cleanQuery = trim($query);
        if ($cleanQuery === '') {
            return 'Empty search query provided.';
        }

        $cacheKey = 'neuron_rag_search_v3_' . md5(mb_strtolower($cleanQuery));

        $cachedPayload = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($cleanQuery) {
            $results = $this->runTargetedSearch($cleanQuery);

            $records = [];
            foreach ($results as $item) {
                $id = data_get($item, 'id');
                $title = (string) (data_get($item, 'title') ?? data_get($item, 'name') ?? 'Untitled Record');
                $keywords = (string) (data_get($item, 'keywords') ?? '');
                $description = (string) (data_get($item, 'content') ?? data_get($item, 'description') ?? data_get($item, 'body') ?? '');
                $url = data_get($item, 'url_one') ?? data_get($item, 'url') ?? data_get($item, 'link');
                $file = data_get($item, 'file_path') ?? data_get($item, 'file');
                $image = data_get($item, 'image_path') ?? data_get($item, 'image');

                $dedupKey = $id !== null ? 'id:' . $id : 'title:' . md5($title);
                if (isset($records[$dedupKey])) {
                    continue;
                }

                $records[$dedupKey] = [
                    'id'          => $id,
                    'title'       => $title,
                    'keywords'    => $keywords,
                    'description' => mb_substr(strip_tags($description), 0, 350),
                    'url'         => is_string($url) && $url !== '' ? $url : null,
                    'file'        => is_string($file) && $file !== '' ? $file : null,
                    'image'       => is_string($image) && $image !== '' ? $image : null,
                ];

                if (count($records) >= 6) {
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
            $sourceKey = $rec['id'] !== null ? 'id:' . $rec['id'] : 'title:' . md5($rec['title']);
            static::$capturedSources[$sourceKey] = [
                'id'    => $rec['id'],
                'title' => $rec['title'],
                'url'   => $rec['url'],
                'file'  => $rec['file'],
                'image' => $rec['image'],
            ];
        }

        if (empty($cachedPayload)) {
            return "No matching content records found for query: \"{$cleanQuery}\".";
        }

        $lines = ["Matching content records for \"{$cleanQuery}\":"];
        foreach ($cachedPayload as $rec) {
            $idLabel = $rec['id'] !== null ? "ID {$rec['id']}" : 'ID N/A';
            $line = "- [{$idLabel}] {$rec['title']}";
            if (!empty($rec['keywords'])) {
                $line .= " | Keywords: {$rec['keywords']}";
            }
            if ($rec['description'] !== '') {
                $line .= ": {$rec['description']}";
            }
            if (!empty($rec['url'])) {
                $line .= " | URL: {$rec['url']}";
            }
            if (!empty($rec['file'])) {
                $line .= " | File: {$rec['file']}";
            }
            $lines[] = $line;
        }

        return implode("\n", $lines);
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
            $matches = $this->contentRepo->ReturnSearchMany(
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

            $addItems($matches);
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
}