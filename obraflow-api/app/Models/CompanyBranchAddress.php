<?php

namespace App\Models;

use App\Modules\Registry\Concerns\IsAddress;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyBranchAddress extends Model
{
    use IsAddress;

    protected $table = 'company_branch_addresses';

    protected $fillable = ['company_branch_id'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(CompanyBranch::class, 'company_branch_id');
    }
}
