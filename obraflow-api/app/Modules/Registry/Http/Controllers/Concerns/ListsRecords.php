<?php

namespace App\Modules\Registry\Http\Controllers\Concerns;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Listagem paginada no formato da API (data + meta) e busca textual segura (curingas escapados). */
trait ListsRecords
{
    /**
     * @param  list<string>  $columns
     * @param  string|null  $digitsColumn  coluna de documento (CPF/CNPJ) buscada por dígitos; null quando a tabela não tem
     */
    protected function search(Builder $query, Request $request, array $columns, ?string $digitsColumn = 'document_number'): Builder
    {
        $term = trim((string) $request->query('q', ''));
        if ($term === '') {
            return $query;
        }

        $like = '%'.addcslashes(mb_substr($term, 0, 100), '%_\\').'%';
        $digits = preg_replace('/\D+/', '', $term);

        return $query->where(function (Builder $w) use ($columns, $like, $digits, $digitsColumn) {
            foreach ($columns as $column) {
                $w->orWhere($column, 'like', $like);
            }
            if ($digitsColumn !== null && $digits !== '' && strlen($digits) >= 3) {
                $w->orWhere($digitsColumn, 'like', '%'.$digits.'%');
            }
        });
    }

    /** @param  class-string<JsonResource>  $resource */
    protected function paginated(Builder $query, Request $request, string $resource): JsonResponse
    {
        /** @var LengthAwarePaginator $page */
        $page = $query->paginate(min(max((int) $request->integer('per_page', 25), 1), 100));

        return response()->json([
            'data' => $resource::collection($page->getCollection())->resolve($request),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }
}
