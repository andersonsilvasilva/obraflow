<?php

namespace App\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** subject_type `project` entra na Fase 3. */
class DocumentPermission extends Model
{
    use BelongsToTenant;

    public const SUBJECT_TYPES = ['user', 'role'];

    public const ABILITIES = ['view', 'download', 'edit', 'manage'];

    protected $fillable = ['subject_type', 'subject_id', 'ability'];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
