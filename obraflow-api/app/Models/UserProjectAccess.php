<?php

namespace App\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Nível de acesso de um usuário a uma obra (D-P24). Gravado só pelo ProjectAccess (auditado na etapa 5). */
class UserProjectAccess extends Model
{
    use BelongsToTenant;

    public const LEVELS = ['view', 'edit', 'manage'];

    protected $table = 'user_project_access';

    protected $fillable = ['user_id', 'project_id', 'access_level', 'granted_by'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function granter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
