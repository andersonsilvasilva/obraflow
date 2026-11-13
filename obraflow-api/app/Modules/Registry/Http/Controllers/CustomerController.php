<?php

namespace App\Modules\Registry\Http\Controllers;

use App\Models\Customer;

class CustomerController extends PartyController
{
    protected function model(): string
    {
        return Customer::class;
    }

    protected function nameColumns(): array
    {
        return ['name'];
    }
}
