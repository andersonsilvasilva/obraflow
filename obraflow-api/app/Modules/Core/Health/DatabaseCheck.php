<?php

namespace App\Modules\Core\Health;

use Illuminate\Support\Facades\DB;
use Throwable;

class DatabaseCheck implements HealthCheck
{
    public function name(): string
    {
        return 'database';
    }

    public function passes(): bool
    {
        try {
            DB::connection()->getPdo();
            DB::select('select 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
