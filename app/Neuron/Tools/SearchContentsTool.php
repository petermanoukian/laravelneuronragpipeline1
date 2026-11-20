<?php

namespace App\Neuron\Tools;

use App\Interface\ContentRepositoryInterface;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class SearchContentsTool extends Tool
{
    protected ContentRepositoryInterface $repo;

    public function __construct(ContentRepositoryInterface $repo)
    {
        $this->repo = $repo;
        $this->name = 'search_contents';
        $this->description = 'Search stored knowledge base content entries by keyword or topic across title, content, and keywords.';
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'query',
                type: PropertyType::STRING,
                description: 'Search keyword or phrase to look up in the contents table.',
                required: true
            ),
        ];
    }

    public function __invoke(string $query): string
    {
        $terms = preg_split('/\s+/u', trim($query), -1, PREG_SPLIT_NO_EMPTY) ?: [$query];
        $collected = collect();

        foreach ($terms as $term) {
            $matches = $this->repo->ReturnSearchMany(
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

            $collected = $collected->merge($matches);
        }

        $items = $collected->unique('id')->take(8)->values();

        if ($items->isEmpty()) {
            return 'No matching records found in contents.';
        }

        return $items->map(function ($item) {
            $urls = array_filter([$item->url_one ?? null, $item->url_two ?? null, $item->url_three ?? null]);
            $lines = [
                "ID: {$item->id}",
                "Title: {$item->title}",
                "Keywords: " . ($item->keywords ?? ''),
                "Content: {$item->content}",
            ];

            if (!empty($urls)) {
                $lines[] = "URLs: " . implode(', ', $urls);
            }

            if (!empty($item->file_path)) {
                $lines[] = "File: {$item->file_path}";
            }

            if (!empty($item->image_path)) {
                $lines[] = "Image: {$item->image_path}";
            }

            return implode("\n", $lines);
        })->implode("\n---\n");
    }
}