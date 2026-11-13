<?php

namespace App\Models;

use App\Modules\Registry\Concerns\IsContact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierContact extends Model
{
    use IsContact;

    protected $table = 'supplier_contacts';

    protected $fillable = ['supplier_id'];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }
}
