<?php

namespace App\Modules\Access\Console;

use App\Modules\Access\Services\PermissionCatalog;
use Illuminate\Console\Command;

class SyncPermissionsCommand extends Command
{
    protected $signature = 'obraflow:sync-permissions';

    protected $description = 'Instala/atualiza o catálogo global de permissões (idempotente; nunca apaga).';

    public function handle(PermissionCatalog $catalog): int
    {
        $result = $catalog->sync();

        $this->info(sprintf('Permissões: %d criadas, %d já existentes.', count($result['created']), count($result['existing'])));

        return self::SUCCESS;
    }
}
