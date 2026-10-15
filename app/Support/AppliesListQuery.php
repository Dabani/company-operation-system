<?php

namespace App\Support;

use App\Exports\ListExport;
use App\Services\OrganizationContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Shared list behaviour: ?search= &sort= &direction= &page= &per_page= &filter[col]= &export=csv|xlsx
 */
trait AppliesListQuery
{
    protected function respondList(
        Request $request,
        Builder $query,
        string $resource,
        array $searchable = [],
        array $sortable = ['created_at'],
        array $filterable = [],
        array $exportColumns = []
    ) {
        if ($s = trim((string) $request->query('search', ''))) {
            $query->where(function (Builder $q) use ($searchable, $s) {
                foreach ($searchable as $col) {
                    str_contains($col, '.')
                        ? $q->orWhereRelation(...$this->relationLike($col, $s))
                        : $q->orWhere($col, 'like', "%{$s}%");
                }
            });
        }

        foreach ((array) $request->query('filter', []) as $col => $value) {
            if (in_array($col, $filterable, true) && $value !== '' && $value !== null) {
                $query->where($col, $value);
            }
        }

        $sort = $request->query('sort');
        $dir = strtolower((string) $request->query('direction', 'asc')) === 'desc' ? 'desc' : 'asc';
        if ($sort && in_array($sort, $sortable, true)) {
            $query->orderBy($sort, $dir);
        } else {
            $query->orderBy($sortable[0] ?? 'created_at', $sortable[0] === 'created_at' ? 'desc' : 'asc');
        }

        if ($format = $request->query('export')) {
            abort_unless(app(OrganizationContext::class)->can('data.export'), 403);
            abort_unless(in_array($format, ['csv', 'xlsx'], true), 422);
            $rows = $resource::collection($query->limit(10000)->get())->resolve($request);
            $keys = $exportColumns ?: array_keys($rows[0] ?? []);
            $data = array_map(fn ($r) => array_map(fn ($k) => is_scalar($r[$k] ?? null) ? $r[$k] : '', $keys), $rows);
            return Excel::download(new ListExport($data, $keys), 'export-' . now()->format('Ymd-His') . '.' . $format);
        }

        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);
        return $resource::collection($query->paginate($perPage)->withQueryString());
    }

    private function relationLike(string $path, string $term): array
    {
        [$rel, $col] = explode('.', $path, 2);
        return [$rel, $col, 'like', "%{$term}%"];
    }
}
