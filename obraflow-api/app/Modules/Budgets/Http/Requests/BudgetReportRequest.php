<?php

namespace App\Modules\Budgets\Http\Requests;

use App\Modules\Budgets\Report\BudgetReportService;
use App\Modules\Budgets\Report\ReportOptions;
use App\Modules\Teams\Http\Requests\TenantRequest;
use Illuminate\Validation\Rule;

/** Opções do relatório do orçamento (FASE4D_MODELO §2; D-P83, D-P85). Textos só para o arquivo (não são gravados). */
class BudgetReportRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'format' => ['required', Rule::in(BudgetReportService::AVAILABLE)],
            'parts' => ['sometimes', 'array'],
            'parts.*' => ['string', Rule::in(ReportOptions::OPTIONAL_PARTS)],
            'hide_bdi' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'place' => ['sometimes', 'nullable', 'string', 'max:120'],
            'technician_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'technician_registry' => ['sometimes', 'nullable', 'string', 'max:60'],
            'technician_art' => ['sometimes', 'nullable', 'string', 'max:60'],
        ];
    }

    public function messages(): array
    {
        return ['format.in' => 'Formato indisponível. Use: '.implode(', ', BudgetReportService::AVAILABLE).'.'];
    }
}
