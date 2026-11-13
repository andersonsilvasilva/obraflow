<?php

namespace App\Modules\Registry\Http\Controllers;

use App\Models\Supplier;

class SupplierController extends PartyController
{
    protected function model(): string
    {
        return Supplier::class;
    }

    protected function nameColumns(): array
    {
        return ['legal_name', 'trade_name'];
    }
}
