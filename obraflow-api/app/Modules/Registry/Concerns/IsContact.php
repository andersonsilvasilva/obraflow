<?php

namespace App\Modules\Registry\Concerns;

use App\Modules\Tenancy\Concerns\BelongsToTenant;

/** Comportamento comum dos contatos dedicados (D-P12). `primary_marker` é coluna gerada: nunca é gravada. */
trait IsContact
{
    use BelongsToTenant;

    protected function initializeIsContact(): void
    {
        $this->mergeFillable(['name', 'role', 'email', 'phone', 'is_primary']);
        $this->mergeCasts(['is_primary' => 'boolean']);
    }
}
