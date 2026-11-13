<?php

namespace App\Models;

use App\Modules\Registry\Concerns\IsRegistryParty;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompanyBranch extends Model
{
    use IsRegistryParty;

    protected $fillable = ['company_id', 'name', 'document_number', 'status'];

    protected $attributes = ['status' => 'active'];

    public function addresses(): HasMany
    {
        return $this->hasMany(CompanyBranchAddress::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(CompanyBranchContact::class);
    }
}
