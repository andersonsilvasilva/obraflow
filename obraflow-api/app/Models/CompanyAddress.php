<?php

namespace App\Models;

use App\Modules\Registry\Concerns\IsAddress;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyAddress extends Model
{
    use IsAddress;

    protected $table = 'company_addresses';

    protected $fillable = ['company_id'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }
}
