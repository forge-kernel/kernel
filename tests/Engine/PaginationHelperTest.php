<?php

declare(strict_types=1);

namespace Forge\tests\Engine;

use App\Modules\ForgeTesting\Attributes\Group;
use App\Modules\ForgeTesting\Attributes\Test;
use App\Modules\ForgeTesting\TestCase;
use App\Modules\ForgeRouter\Http\Request;
use Forge\Traits\PaginationHelper;

#[Group('helpers')]
final class PaginationHelperTest extends TestCase
{
    private function runner(): object
    {
        return new class {
            use PaginationHelper;

            public function params(Request $req): array
            {
                return $this->getPaginationParams($req);
            }
        };
    }

    private function makeRequest(array $query = []): Request
    {
        return new Request($query, [], ['REQUEST_URI' => '/'], 'GET', []);
    }

    #[Test('getPaginationParams defaults page to 1 and limit to 15')]
    public function defaults(): void
    {
        $params = $this->runner()->params($this->makeRequest());
        $this->assertEquals(1, $params['page']);
        $this->assertEquals(15, $params['limit']);
        $this->assertEquals('created_at', $params['column']);
        $this->assertEquals('ASC', $params['direction']);
        $this->assertEquals('', $params['search']);
    }

    #[Test('getPaginationParams reads page from query')]
    public function reads_page(): void
    {
        $params = $this->runner()->params($this->makeRequest(['page' => '3']));
        $this->assertEquals(3, $params['page']);
    }

    #[Test('getPaginationParams reads per_page from query')]
    public function reads_per_page(): void
    {
        $params = $this->runner()->params($this->makeRequest(['per_page' => '50']));
        $this->assertEquals(50, $params['limit']);
    }

    #[Test('getPaginationParams enforces max limit of 100')]
    public function max_limit_capped(): void
    {
        $params = $this->runner()->params($this->makeRequest(['per_page' => '500']));
        $this->assertEquals(100, $params['limit']);
    }

    #[Test('getPaginationParams enforces min page of 1')]
    public function min_page_clamped(): void
    {
        $params = $this->runner()->params($this->makeRequest(['page' => '-5']));
        $this->assertEquals(1, $params['page']);
    }

    #[Test('getPaginationParams reads search from query')]
    public function reads_search(): void
    {
        $params = $this->runner()->params($this->makeRequest(['search' => 'john']));
        $this->assertEquals('john', $params['search']);
    }

    #[Test('getPaginationParams reads sort and direction from query')]
    public function reads_sort_direction(): void
    {
        $params = $this->runner()->params($this->makeRequest(['sort' => 'name', 'direction' => 'DESC']));
        $this->assertEquals('name', $params['column']);
        $this->assertEquals('DESC', $params['direction']);
    }

    #[Test('getPaginationParams extracts filter[] keys into filters map')]
    public function extracts_filters(): void
    {
        $params = $this->runner()->params($this->makeRequest([
            'filter[status]' => 'active',
            'filter[role]' => 'admin',
        ]));
        $this->assertEquals('active', $params['filters']['status']);
        $this->assertEquals('admin', $params['filters']['role']);
    }

    #[Test('getPaginationParams ignores non-numeric page value')]
    public function ignores_non_numeric_page(): void
    {
        $params = $this->runner()->params($this->makeRequest(['page' => 'abc']));
        $this->assertEquals(1, $params['page']);
    }
}
