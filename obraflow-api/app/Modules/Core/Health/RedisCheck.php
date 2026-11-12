<?php

namespace App\Modules\Core\Health;

use Illuminate\Support\Facades\Redis;
use Throwable;

class RedisCheck implements HealthCheck
{
    public function name(): string
    {
        return 'redis';
    }

    public function passes(): bool
    {
        try {
            Redis::connection()->ping();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
