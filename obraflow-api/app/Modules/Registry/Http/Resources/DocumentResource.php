<?php

namespace App\Modules\Registry\Http\Resources;

use App\Models\Document;
use App\Models\DocumentLink;
use App\Models\DocumentVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Metadados do documento. NUNCA expõe storage_disk/storage_key: o arquivo só é acessado pela URL assinada.
 *
 * @mixin Document
 */
class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'title' => $this->title,
            'category_id' => $this->category_id,
            'status' => $this->status,
            'visibility' => $this->visibility,
            'current_version' => $this->whenLoaded('currentVersion', fn () => $this->currentVersion ? self::version($this->currentVersion) : null),
            'versions' => $this->whenLoaded('versions', fn () => $this->versions->sortByDesc('version_number')->map(fn ($v) => self::version($v))->values()),
            'links' => $this->whenLoaded('links', fn () => $this->links->map(fn (DocumentLink $l) => [
                'id' => $l->id, 'linkable_type' => $l->linkable_type, 'linkable_id' => $l->linkable_id,
            ])->values()),
            'permissions' => $this->when($this->relationLoaded('permissions'), fn () => $this->permissions->map(fn ($p) => [
                'subject_type' => $p->subject_type, 'subject_id' => $p->subject_id, 'ability' => $p->ability,
            ])->values()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    private static function version(DocumentVersion $v): array
    {
        return [
            'version_number' => $v->version_number, 'original_name' => $v->original_name, 'mime_type' => $v->mime_type,
            'size_bytes' => $v->size_bytes, 'sha256' => $v->sha256, 'uploaded_by' => $v->uploaded_by,
            'created_at' => $v->created_at?->toIso8601String(),
        ];
    }
}
