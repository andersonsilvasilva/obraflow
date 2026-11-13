<?php

namespace App\Models;

use App\Modules\Registry\Concerns\IsContact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerContact extends Model
{
    use IsContact;

    protected $table = 'customer_contacts';

    protected $fillable = ['customer_id'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }
}
