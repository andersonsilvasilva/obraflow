# Validação empírica do modelo da Fase 1

Evidência de que o esquema proposto em [FASE1_MODELO.md](../FASE1_MODELO.md) **se comporta como projetado** num MySQL real. **Não são migrations do projeto.**

| Arquivo | Conteúdo |
|---|---|
| `phase1.sql` | DDL **protótipo** das 15 tabelas (descartável; as migrations reais só serão criadas após aprovação) |
| `validate.php` | Roteiro: 87 verificações — regras de banco, privilégios, fuso, plugin de autenticação e **Spatie 8.5.0 + Sanctum 4.4 reais** |
| `result.txt` | Saída da execução: **87 aprovados, 0 reprovados** |

## Como foi executado

Uma instância **MySQL 8.0.30 descartável** (mesmo binário do Laragon, pasta temporária, porta 3399, somente `127.0.0.1`), com os mesmos padrões do servidor local (`log_bin=1`, `log_bin_trust_function_creators=0`, `time_zone=SYSTEM`, `STRICT_TRANS_TABLES`). Ao final, a instância foi **desligada e apagada**. O MySQL do Laragon (porta 3306) **não foi tocado**: nenhum banco `obraflow*` existe nele.

## Como reproduzir (em uma instância descartável — nunca no servidor compartilhado)

```bash
mysqld --initialize-insecure --datadir=<pasta-temporaria>
mysqld --datadir=<pasta-temporaria> --port=3399 --bind-address=127.0.0.1 --mysqlx=OFF
mysql -uroot -h127.0.0.1 -P3399 < phase1.sql
php validate.php        # ajuste $api (caminho de obraflow-api) no topo da Parte 2
```

## Limites

- Roda como `root` na instância descartável; os testes de privilégio criam usuários próprios.
- **Não** cobre o MySQL **8.4** (sem binário disponível) — repetir no CI/Docker.
- **Não** cobre Redis, middleware de tenant, autenticação nem `BelongsToTenant` (ainda não implementados).
