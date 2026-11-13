<?php

namespace App\Models;

use App\Modules\Registry\Concerns\IsContact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyContact extends Model
{
    use IsContact;

    protected $table = 'company_contacts';

    protected $fillable = ['company_id'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }
}
