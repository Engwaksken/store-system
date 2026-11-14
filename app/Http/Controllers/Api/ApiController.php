<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

abstract class ApiController extends Controller
{
    protected function perPage(Request $request): int
    {
        return max(1, min(50, $request->integer('per_page') ?: 20));
    }

    /** `{data, meta}` page of a query, each row mapped with $present. */
    protected function paginated(Request $request, Builder $query, Closure $present): JsonResponse
    {
        return $this->page($query->paginate($this->perPage($request)), $present);
    }

    /** Same shape for a list already filtered in PHP (permission checks SQL cannot express). */
    protected function paginatedCollection(Request $request, Collection $all, Closure $present): JsonResponse
    {
        $page = max(1, $request->integer('page') ?: 1);
        $per = $this->perPage($request);

        return $this->page(new LengthAwarePaginator($all->forPage($page, $per)->values(), $all->count(), $per, $page), $present);
    }

    private function page(LengthAwarePaginator $p, Closure $present): JsonResponse
    {
        return response()->json([
            'data' => collect($p->items())->map($present)->values(),
            'meta' => ['current_page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'per_page' => $p->perPage(), 'total' => $p->total()],
        ]);
    }

    protected function like(string $term): string
    {
        return '%'.addcslashes($term, '%_\\').'%';
    }
}
