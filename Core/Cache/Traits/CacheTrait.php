<?php

declare(strict_types=1);

namespace Forge\Core\Cache\Traits;

trait CacheTrait
{
    private function handleData(mixed $raw): mixed
    {
        if ($raw === null) {
            return null;
        }

        $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        if (!isset($payload['d'])) {
            return null;
        }

        if (($payload['c'] ?? null) === null || !class_exists($payload['c'])) {
            return $payload['d'];
        }

        $class = $payload['c'];
        
        // Special handling for Paginator objects
        if ($class === \App\Modules\ForgeSqlOrm\ORM\Paginator::class) {
            return $this->reconstructPaginator($payload['d']);
        }
        
        // Special handling for Model objects
        if (is_subclass_of($class, \App\Modules\ForgeSqlOrm\ORM\Model::class)) {
            return $this->reconstructModel($class, $payload['d']);
        }
        
        return new $class($payload['d']);
    }
    
    /**
     * Reconstruct Model from cached array data
     */
    private function reconstructModel(string $class, array $data): \App\Modules\ForgeSqlOrm\ORM\Model
    {
        // Use the model's fromRow method for proper reconstruction
        return $class::fromRow($data);
    }
    
    /**
     * Reconstruct Paginator from cached array data
     */
    private function reconstructPaginator(array $data): \App\Modules\ForgeSqlOrm\ORM\Paginator
    {
        $meta = $data['meta'] ?? [];
        
        return new \App\Modules\ForgeSqlOrm\ORM\Paginator(
            items: $data['data'] ?? [],
            total: $meta['total'] ?? 0,
            perPage: $meta['per_page'] ?? 10,
            currentPage: $meta['current_page'] ?? 1,
            cursor: null,
            sortColumn: $meta['sort']['column'] ?? 'created_at',
            sortDirection: $meta['sort']['direction'] ?? 'asc',
            filters: $meta['filters'] ?? [],
            search: $meta['search'] ?? null,
            searchFields: [],
            baseUrl: '',
            queryParams: []
        );
    }
}
