<?php

namespace App\Models;

use App\Modules\Registry\Concerns\HasPublicUuid;
use App\Modules\Registry\Concerns\NormalizesDigits;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Pessoa jurídica administrada pelo tenant (D-P11). CNPJ só com dígitos. */
class Company extends Model
{
    use BelongsToTenant, HasPublicUuid, NormalizesDigits, SoftDeletes;

    protected $fillable = ['legal_name', 'trade_name', 'document_number', 'state_registration', 'municipal_registration', 'email', 'phone', 'status'];

    protected $attributes = ['status' => 'active'];

    protected function documentNumber(): Attribute
    {
        return Attribute::make(set: fn (?string $v) => self::digits($v));
    }

    public function branches(): HasMany
    {
        return $this->hasMany(CompanyBranch::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(CompanyAddress::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(CompanyContact::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function suppliers(): HasMany
    {
        return $this->hasMany(Supplier::class);
    }
}
