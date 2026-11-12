<?php

namespace App\Models;

use Spatie\Permission\Models\Permission as SpatiePermission;

/** Catálogo GLOBAL de permissões (instalado por `obraflow:sync-permissions`). */
class Permission extends SpatiePermission {}
