<?php

namespace App\Models;

use App\Modules\Registry\Concerns\IsAddress;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectAddress extends Model
{
    use IsAddress;

    protected $table = 'project_addresses';

    protected $fillable = ['project_id'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
