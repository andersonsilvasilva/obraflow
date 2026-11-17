<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Arquivo importado numa base (nome, SHA-256, contagens) — rastreio da origem dos dados (D-P30). */
class ReferenceImport extends Model
{
    protected $fillable = ['dataset_id', 'kind', 'file_name', 'file_sha256', 'rows_read', 'rows_imported', 'imported_at'];

    protected function casts(): array
    {
        return ['imported_at' => 'datetime', 'rows_read' => 'integer', 'rows_imported' => 'integer'];
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(ReferenceDataset::class, 'dataset_id');
    }
}
