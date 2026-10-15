# Hotfix — Agenda HTTP 500 (15/10/2026)

## Diagnóstico confirmado pelo log

`Call to undefined method App\Services\InternalCalendarSlotService::releaseInactiveAllocations()` em `InternalCalendarSlotService.php:702`.

O método era chamado durante o cálculo de capacidade publicado (`capacitySummariesForSlots`), mas foi removido da classe na última atualização de concorrência. Esse método existia numa versão anterior do projeto. Não é erro de `tenant_uuid`, conexão ou CSS.

## Correção

Restaurado `releaseInactiveAllocations()` com reconciliação por compromisso, transações e locks na ordem *compromisso -> horário publicado*. Apenas agendamentos em `cancelled`, `rejected`, `rescheduled` têm suas alocações liberadas. Em caso de falha na reconciliação, as alterações não são consideradas confirmadas; no caminho de uma transação externa o erro é propagado. Não há migration.

## Aplicação segura

1. Faça backup do banco e do arquivo `app/Services/InternalCalendarSlotService.php` da instalação.
2. Extraia **somente** `app/Services/InternalCalendarSlotService.php` do patch, mantendo a estrutura de diretórios. O ZIP também contém documentação e teste, opcionais.
3. No diretório da aplicação (`/var/www/html` no log), execute: `php -l app/Services/InternalCalendarSlotService.php`.
4. Se existir OPcache persistente, reinicie o serviço PHP pela interface do EasyPanel, para carregar a classe atualizada. Não reinicie o MySQL nem limpe as reservas.
5. Abra Agenda → Disponibilidades, Visão geral, Pré-agendamentos e Configurações. Confirme que não há novo erro no log.
6. Confira capacidade com um horário 1/1 e outro 2/4; compromissos cancelados devem liberar uma vaga, confirmados não.
7. Se reaparecer um erro, envie as últimas 30 linhas do log, ocultando dados sensíveis.

### Testes

`php tests/Feature/calendar-inactive-allocation-missing-method-20261015-smoke.php`

Este teste cobre a presença do método e invariantes estruturais. A transação concorrente e a integração MySQL **não foram testadas no servidor de produção**; homologar antes de liberar novas ações automáticas.
