<?php

namespace App\Repository;

use App\Models\Content;
use App\Interface\ContentRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EloquentContentRepository implements ContentRepositoryInterface
{
    protected $model;

    public function __construct(Content $model)
    {
        $this->model = $model;
    }

    /**
     * Unified query builder with standard Rules 1-5.
     */
    protected function prepareQuery(array $filters, string $orderBy, string $orderDir, array $related, array $fields): Builder
    {
        // Rule 5: Fields (default '*')
        $query = $this->model->select($fields);

        // Rule 4: Related Tables
        if (!empty($related)) {
            $query->with($related);
        }

        // Rule 1: Additional filters
        if (!empty($filters)) {
            $query->where($filters);
        }

        // Rule 2,3: Order By (default ID DESC)
        return $query->orderBy($orderBy, $orderDir);
    }

    /**
     * Unified advanced search logic with Rules 6-8.
     */
    protected function prepareSearchQuery(Builder $query, array $searchFields, string $boolean, string $operator): Builder
    {
        if (!empty($searchFields)) {
            // Group the search in a where clause to avoid logical overlap with existing $filters
            $query->where(function ($subQuery) use ($searchFields, $boolean, $operator) {
                // Rule 7,8: Combine fields (AND/OR) and operators (LIKE/=)
                foreach ($searchFields as $field => $value) {
                    $op = strtoupper($operator) === 'LIKE' ? 'LIKE' : '=';
                    $val = $op === 'LIKE' ? "%{$value}%" : $value;
                    $bool = strtoupper($boolean) === 'OR' ? 'orWhere' : 'where';

                    $subQuery->$bool($field, $op, $val);
                }
            });
        }
        return $query;
    }

    // 11 REQUIRED FUNCTIONS:

    public function ReturnMany(array $filters = [], string $orderBy = 'id', string $orderDir = 'desc', array $related = [], array $fields = ['*']): Collection
    {
        return $this->prepareQuery($filters, $orderBy, $orderDir, $related, $fields)->get();
    }

    public function ReturnManyPaginated(array $filters = [], string $orderBy = 'id', string $orderDir = 'desc', array $related = [], array $fields = ['*'], int $perPage = 15): LengthAwarePaginator
    {
        return $this->prepareQuery($filters, $orderBy, $orderDir, $related, $fields)->paginate($perPage);
    }

    public function ReturnOne(array $filters = [], array $related = [], array $fields = ['*']): ?Model
    {
        return $this->prepareQuery($filters, 'id', 'desc', $related, $fields)->first();
    }

    public function ReturnOneByID(int $id, array $related = [], array $fields = ['*']): ?Model
    {
        // Rule 4: related tables option
        // Rule 5: fields selective fields
        $query = $this->model->select($fields);
        if (!empty($related)) {
            $query->with($related);
        }
        return $query->find($id);
    }

    public function ReturnSearchMany(array $filters = [], string $orderBy = 'id', string $orderDir = 'desc', array $related = [], array $fields = ['*'], array $searchFields = [], string $boolean = 'AND', string $operator = 'LIKE'): Collection
    {
        $query = $this->prepareQuery($filters, $orderBy, $orderDir, $related, $fields);
        return $this->prepareSearchQuery($query, $searchFields, $boolean, $operator)->get();
    }

    public function ReturnSearchPaginated(array $filters = [], string $orderBy = 'id', string $orderDir = 'desc', array $related = [], array $fields = ['*'], array $searchFields = [], string $boolean = 'AND', string $operator = 'LIKE', int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->prepareQuery($filters, $orderBy, $orderDir, $related, $fields);
        return $this->prepareSearchQuery($query, $searchFields, $boolean, $operator)->paginate($perPage);
    }

    public function ReturnSearchOne(array $filters = [], array $related = [], array $fields = ['*'], array $searchFields = [], string $boolean = 'AND', string $operator = 'LIKE'): ?Model
    {
        // We reuse ReturnOne structure and add advanced search logic.
        $query = $this->prepareQuery($filters, 'id', 'desc', $related, $fields);
        return $this->prepareSearchQuery($query, $searchFields, $boolean, $operator)->first();
    }

    public function Store(array $data)
    {
        return $this->model->create($data);
    }

    public function Update(int $id, array $data)
    {
        $content = $this->ReturnOneByID($id);
        if ($content) {
            $content->update($data);
            return $content;
        }
        return false;
    }

    public function Delete(int $id, array $relatedTablesToCleanup = [])
    {
        $content = $this->ReturnOneByID($id);
        if (!$content) return false;

        return DB::transaction(function () use ($content, $relatedTablesToCleanup) {
            // Process the required clean up rule
            if (!empty($relatedTablesToCleanup)) {
                // Here you'd call a content-specific service or handle deletion of related records
                // e.g. deleting files referenced in `file_path`, associated metadata, tags, etc.
            }
            return $content->delete();
        });
    }

    public function DeleteMany(array $ids, array $relatedTablesToCleanup = [])
    {
        if (empty($ids)) return false;

        return DB::transaction(function () use ($ids, $relatedTablesToCleanup) {
            // We iterate and use individual Delete logic to respect the cleanup rule for each entry.
            foreach ($ids as $id) {
                $this->Delete($id, $relatedTablesToCleanup);
            }
            return true;
        });
    }
}