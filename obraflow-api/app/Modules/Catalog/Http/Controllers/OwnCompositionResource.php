<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Models\OwnComposition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Composição própria na API (listagem). @mixin OwnComposition */
class OwnCompositionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return OwnCompositionController::data($this->resource);
    }
}
