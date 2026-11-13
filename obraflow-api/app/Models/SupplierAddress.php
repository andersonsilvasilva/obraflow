<?php

namespace App\Models;

use App\Modules\Registry\Concerns\IsAddress;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierAddress extends Model
{
    use IsAddress;

    protected $table = 'supplier_addresses';

    protected $fillable = ['supplier_id'];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }
}
