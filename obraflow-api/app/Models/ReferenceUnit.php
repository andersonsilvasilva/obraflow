<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Unidade como publicada pela fonte (M, M2, UN, H, CHP…). Global, separada das unidades do tenant. */
class ReferenceUnit extends Model
{
    protected $fillable = ['code'];
}
