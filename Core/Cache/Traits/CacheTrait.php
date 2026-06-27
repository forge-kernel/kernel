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

        $paginatorClass = 'App\Modules\ForgeSqlOrm\ORM\Paginator';
        $modelClass = 'App\Modules\ForgeSqlOrm\ORM\Model';

        if ($class === $paginatorClass) {
            return $this->reconstructPaginator($payload['d']);
        }

        if (class_exists($modelClass) && is_subclass_of($class, $modelClass)) {
            return $this->reconstructModel($class, $payload['d']);
        }

        return new $class($payload['d']);
    }

    private function reconstructModel(string $class, array $data): object
    {
        return $class::fromRow($data);
    }

    private function reconstructPaginator(array $data): object
    {
        $meta = $data['meta'] ?? [];

        $paginatorClass = 'App\Modules\ForgeSqlOrm\ORM\Paginator';

        return new $paginatorClass(
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
