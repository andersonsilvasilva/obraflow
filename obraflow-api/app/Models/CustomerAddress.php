<?php

namespace App\Models;

use App\Modules\Registry\Concerns\IsAddress;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerAddress extends Model
{
    use IsAddress;

    protected $table = 'customer_addresses';

    protected $fillable = ['customer_id'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }
}
