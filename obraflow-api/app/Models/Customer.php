<?php

namespace App\Models;

use App\Modules\Registry\Concerns\HasPublicUuid;
use App\Modules\Registry\Concerns\IsRegistryParty;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Pertence a uma empresa do tenant (D-P17); `type` person exige CPF, company exige CNPJ. */
class Customer extends Model
{
    use HasPublicUuid, IsRegistryParty;

    public const TYPES = ['person', 'company'];

    protected $fillable = ['company_id', 'type', 'document_number', 'name', 'email', 'phone', 'status'];

    protected $attributes = ['status' => 'active'];

    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(CustomerContact::class);
    }
}
