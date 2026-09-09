# RS Connect 36.28.2

## Objetivo

Corrigir dois pontos do painel operacional:

1. não exibir fragmentos de chaves, senhas ou tokens na área de ambiente;
2. impedir que clientes/pacientes atuais voltem a exigir uma triagem de demanda antes da agenda.

## Segurança do painel

Valores sensíveis agora são representados somente como **Configurado** ou **Não configurado**. Nenhum prefixo ou sufixo do segredo é enviado para a tela.

## Continuidade de clientes e pacientes

Foi identificada uma divergência entre a regra padrão do serviço e a tela de configuração de agentes: `Paciente atual` ainda podia ser salvo com `require_demand_before_pre_schedule = 1`.

A versão corrige em três camadas:

- interface usa o padrão correto;
- backend força `0` para os grupos `customer` e `patient`;
- migration `104_customer_patient_continuity_guard.sql` normaliza dados históricos.

## Deploy

```bash
php bin/migrate.php up
php bin/migrate.php status
php bin/migrate.php verify
```

Reinicie o PHP-FPM/container para limpar OPcache.
