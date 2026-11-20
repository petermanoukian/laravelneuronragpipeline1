<?php

namespace App\Interface;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface ContentRepositoryInterface
{
    /**
     * Return multiple rows (Rules 1-5).
     * Rule 1: Additional filters.
     * Rule 2,3: Order by field and direction.
     * Rule 4: Related tables option.
     * Rule 5: All fields ('*') or certain fields.
     */
    public function ReturnMany(array $filters = [], string $orderBy = 'id', string $orderDir = 'desc', array $related = [], array $fields = ['*']): Collection;

    /**
     * Return multiple paginated rows (Rules 1-5 + 9).
     * Rule 9: Paginated or not.
     */
    public function ReturnManyPaginated(array $filters = [], string $orderBy = 'id', string $orderDir = 'desc', array $related = [], array $fields = ['*'], int $perPage = 15): LengthAwarePaginator;

    /**
     * Return a single row based on filters (Rules 1-3).
     * Rule 3: Additional filters.
     * Rule 2: Related tables.
     * Rule 1: All fields or certain.
     */
    public function ReturnOne(array $filters = [], array $related = [], array $fields = ['*']): ?Model;

    /**
     * Return a single row by its ID (2 Parameters).
     * P1: ID.
     * P2: related tables.
     * P3: selective fields.
     */
    public function ReturnOneByID(int $id, array $related = [], array $fields = ['*']): ?Model;

    /**
     * Return multiple rows matching an advanced search (Rules 1-8).
     * Rules 1-5: Filters, Ordering, Related Tables, Selected Fields.
     * Rule 6: Search fields.
     * Rule 7: AND or OR boolean.
     * Rule 8: Like or exact operator.
     */
    public function ReturnSearchMany(array $filters = [], string $orderBy = 'id', string $orderDir = 'desc', array $related = [], array $fields = ['*'], array $searchFields = [], string $boolean = 'AND', string $operator = 'LIKE'): Collection;

    /**
     * Return multiple paginated rows matching an advanced search (Rules 1-9).
     */
    public function ReturnSearchPaginated(array $filters = [], string $orderBy = 'id', string $orderDir = 'desc', array $related = [], array $fields = ['*'], array $searchFields = [], string $boolean = 'AND', string $operator = 'LIKE', int $perPage = 15): LengthAwarePaginator;

    /**
     * Return a single row matching a search query.
     */
    public function ReturnSearchOne(array $filters = [], array $related = [], array $fields = ['*'], array $searchFields = [], string $boolean = 'AND', string $operator = 'LIKE'): ?Model;

    /**
     * Store a newly created content resource.
     */
    public function Store(array $data);

    /**
     * Update an existing content resource.
     */
    public function Update(int $id, array $data);

    /**
     * Delete a single content resource by ID.
     * Rule: array of related tables to cleanup.
     */
    public function Delete(int $id, array $relatedTablesToCleanup = []);

    /**
     * Delete multiple content resources by IDs.
     * Rule: array of related tables to cleanup.
     */
    public function DeleteMany(array $ids, array $relatedTablesToCleanup = []);
}