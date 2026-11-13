<?php

namespace App\Models;

use App\Modules\Registry\Concerns\HasPublicUuid;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Metadados de documento (D-P15). current_version_id e created_by só são definidos pelo DocumentStorage
 * (não mass-assignable).
 */
class Document extends Model
{
    use BelongsToTenant, HasPublicUuid;

    public const STATUSES = ['active', 'archived'];

    public const VISIBILITIES = ['private', 'restricted'];

    protected $fillable = ['category_id', 'title', 'status', 'visibility'];

    protected $attributes = ['status' => 'active', 'visibility' => 'private'];

    public function category(): BelongsTo
    {
        return $this->belongsTo(DocumentCategory::class, 'category_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class);
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'current_version_id');
    }

    public function links(): HasMany
    {
        return $this->hasMany(DocumentLink::class);
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(DocumentPermission::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
