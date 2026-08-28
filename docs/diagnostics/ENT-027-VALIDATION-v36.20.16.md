# ENT-027 — Relatório de validação v36.20.16

## Validações concluídas

- manifesto com 96 migrations de subida;
- um rollback isolado;
- 8 prefixos históricos duplicados documentados;
- parser validou **1.961 instruções** em migrations, schema e seeds;
- tabelas antecipadas no snapshot foram comparadas com as declarações das migrations e não apresentaram colunas faltantes;
- migration 089 idempotente;
- Dockerfile executa validação offline durante o build;
- Docker Compose aguarda o serviço de migration;
- readiness verifica a conclusão do registro;
- monitoramento verifica pendências, checksum e sequência;
- PHP: **325 arquivos** sem erro de sintaxe;
- JavaScript: **3 arquivos** sem erro;
- JSON: **55 arquivos** válidos;
- Docker Compose: YAML válido;
- suíte ENT-027: **15 verificações aprovadas**;
- suíte geral: **87 aprovações e 9 falhas históricas**.

## Limitação do ambiente

Não havia servidor MySQL/MariaDB disponível no ambiente de validação, e o acesso aos repositórios de pacotes estava indisponível. Por isso, a instalação completa precisa ser homologada na VPS ou em uma máquina com MySQL 8.4.

A validação executada foi estática e comportamental sobre:

- sintaxe PHP;
- parser SQL;
- ordem do manifesto;
- integridade dos arquivos;
- estrutura Docker;
- testes de regressão existentes.

## Roteiro de homologação obrigatório

### Banco atual

```bash
php bin/migrate.php verify
php bin/migrate.php baseline --through=088 --yes
php bin/migrate.php status
```

### Banco vazio de teste

1. crie um banco vazio;
2. configure `DB_*`;
3. execute `php bin/migrate.php install --yes`;
4. execute `php bin/migrate.php status`;
5. valide login, empresas, conversas, CRM, agenda, cobrança, relatórios e Evolution.

### Concorrência

Execute `php bin/migrate.php up` em dois terminais. Um processo deve obter o lock e o outro deve aguardar ou encerrar com mensagem de execução em andamento.
