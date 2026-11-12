<?php

namespace App\Modules\Access\Console;

use App\Modules\Access\Exceptions\ProvisioningException;
use App\Modules\Access\Services\ProvisionTenant;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Str;

/**
 * Provisionamento inicial de um tenant + administrador (sem interface de superadmin nesta fase).
 *
 * Proteções: só existe no console; em produção exige --force (ConfirmableTrait); mostra o resumo e pede
 * confirmação (ou --yes); NUNCA recebe senha por argumento (prompt oculto ou --generate-password);
 * recusa slug/nome/administrador duplicados; tudo numa transação, com auditoria.
 */
class ProvisionTenantCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'obraflow:provision
        {--name= : Nome do tenant}
        {--slug= : Slug único (a-z, 0-9 e hífen); padrão: derivado do nome}
        {--admin-email= : E-mail do administrador}
        {--admin-name= : Nome do administrador}
        {--generate-password : Gera uma senha forte e a exibe UMA vez (para execução sem prompt)}
        {--existing-admin : Permite vincular um usuário que JÁ existe como administrador do novo tenant}
        {--allow-duplicate-name : Permite repetir o nome de um tenant existente}
        {--yes : Não pede confirmação}
        {--force : Obrigatório em produção}';

    protected $description = 'Cria o tenant, o administrador, os papéis de sistema e o vínculo (provisionamento inicial).';

    public function handle(ProvisionTenant $provision): int
    {
        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $name = $this->option('name') ?: ($this->input->isInteractive() ? $this->ask('Nome do tenant') : null);
        $slug = $this->option('slug') ?: ($name ? Str::slug($name) : null);
        $email = $this->option('admin-email') ?: ($this->input->isInteractive() ? $this->ask('E-mail do administrador') : null);
        $adminName = $this->option('admin-name') ?: ($this->input->isInteractive() ? $this->ask('Nome do administrador') : null);

        if (! $name || ! $slug || ! $email || ! $adminName) {
            $this->error('Informe --name, --admin-email e --admin-name (ou execute de forma interativa).');

            return self::FAILURE;
        }

        $this->table(['Campo', 'Valor'], [
            ['Tenant', $name], ['Slug', $slug], ['Administrador', "{$adminName} <{$email}>"],
            ['Usuário já existente?', $this->option('existing-admin') ? 'permitido (--existing-admin)' : 'não (será criado)'],
        ]);

        if (! $this->option('yes') && ! $this->confirm('Criar este tenant e administrador?', false)) {
            $this->warn('Cancelado. Nada foi criado.');

            return self::FAILURE;
        }

        $password = null;
        if (! $this->option('generate-password') && ! $this->option('existing-admin')) {
            if (! $this->input->isInteractive()) {
                $this->error('Sem prompt disponível: use --generate-password (a senha nunca é aceita por argumento).');

                return self::FAILURE;
            }

            $password = $this->secret('Senha do administrador (mín. 12, com maiúscula, minúscula e número)');
            if ($password !== $this->secret('Repita a senha')) {
                $this->error('As senhas não conferem. Nada foi criado.');

                return self::FAILURE;
            }
        }

        try {
            $result = $provision->run(
                $name, $slug, $email, $adminName, $password,
                allowExistingAdmin: (bool) $this->option('existing-admin'),
                allowDuplicateName: (bool) $this->option('allow-duplicate-name'),
            );
        } catch (ProvisioningException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Tenant \"{$result['tenant']->name}\" criado (uuid {$result['tenant']->uuid}).");
        $this->info($result['admin_created']
            ? "Administrador criado: {$result['admin']->email}"
            : "Usuário existente vinculado como administrador: {$result['admin']->email}");

        if ($result['generated_password'] !== null) {
            $this->newLine();
            $this->warn('Senha gerada (exibida UMA única vez — guarde em local seguro e troque no primeiro acesso):');
            $this->line($result['generated_password']);
        }

        return self::SUCCESS;
    }
}
