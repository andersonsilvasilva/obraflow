<?php

namespace App\Models;

use App\Modules\Registry\Concerns\IsContact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyBranchContact extends Model
{
    use IsContact;

    protected $table = 'company_branch_contacts';

    protected $fillable = ['company_branch_id'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(CompanyBranch::class, 'company_branch_id');
    }
}
