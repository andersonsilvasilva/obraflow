<?php

namespace App\Modules\Budgets\Services;

use App\Models\BudgetItem;
use App\Models\BudgetVersion;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * BDI efetivo de cada item (D-P02): o do próprio item; senão o da etapa mais próxima (subindo na árvore);
 * senão o global da versão; senão zero. BDI por escopo vem de `budget_bdi`; o percentual digitado direto no
 * item (`budget_items.bdi_percent`) tem precedência.
 */
final class BdiResolver
{
    /** @return Closure(BudgetItem): string */
    public function for(BudgetVersion $version): Closure
    {
        $parents = DB::table('budget_groups')->where('budget_version_id', $version->id)->pluck('parent_id', 'id')->all();
        $global = null;
        $byGroup = [];
        $byItem = [];

        foreach (DB::table('budget_bdi')->where('budget_version_id', $version->id)->get() as $row) {
            match ($row->scope) {
                'global' => $global = (string) $row->percent,
                'group' => $byGroup[$row->group_id] = (string) $row->percent,
                'item' => $byItem[$row->item_id] = (string) $row->percent,
                default => null,
            };
        }

        return function (BudgetItem $item) use ($parents, $global, $byGroup, $byItem): string {
            if ($item->bdi_percent !== null) {
                return (string) $item->bdi_percent;
            }
            if (isset($byItem[$item->id])) {
                return $byItem[$item->id];
            }
            for ($g = $item->group_id; $g !== null; $g = $parents[$g] ?? null) {
                if (isset($byGroup[$g])) {
                    return $byGroup[$g];
                }
            }

            return $global ?? '0';
        };
    }
}
