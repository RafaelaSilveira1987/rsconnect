# RS Connect v36.22.0 — publicação

## 1. Atualizar os arquivos

Substitua os arquivos da aplicação pelo pacote v36.22.0 e reinicie/reimplante o serviço.

## 2. Aplicar a migration

```bash
php bin/migrate.php verify
php bin/migrate.php status
php bin/migrate.php up
php bin/migrate.php status
```

Resultado esperado:

```text
091_after_hours_monitor_and_quote_requests.sql aplicada
98 aplicada(s), 0 pendente(s), 0 divergente(s)
```

Não execute `baseline` em banco que já possui histórico.

## 3. Configurar a retomada pós-horário

Acesse:

```text
Saúde do sistema > Fila/IA > Retomada pós-horário
```

Mantenha a rotina ativa, escolha o intervalo e use **Executar monitor agora** para homologar.

No EasyPanel, crie uma tarefa agendada no serviço da aplicação a cada 5 minutos:

```bash
php /var/www/html/bin/ai-after-hours-recovery.php
```

O sistema respeita o intervalo salvo na tela, por padrão 15 minutos, e bloqueia execuções simultâneas.

Alternativa HTTP:

```text
GET /webhooks/ai-after-hours-recovery?token=SEU_TOKEN
```

Para usar o endpoint HTTP, configure no ambiente:

```text
AFTER_HOURS_MONITOR_TOKEN=uma-chave-longa-e-aleatoria
```

Quando essa variável estiver vazia, o sistema reutiliza `AI_REPROCESS_CRON_TOKEN`.

## 4. Ativar os alertas de orçamento

Acesse:

```text
Comercial > Solicitações de orçamento
```

Ative a identificação e configure:

- prazo de retorno;
- responsável padrão;
- criação de tarefa;
- alerta na conversa;
- notificação interna;
- etapa de proposta/orçamento;
- modo de movimentação do card.

O recurso começa desativado para não alterar a operação de empresas que não usam o Comercial.

## 5. Homologação rápida

1. Fora do horário, envie uma mensagem e confirme que ela aparece na fila preservada.
2. Execute o monitor manual depois de abrir o expediente e confirme a retomada da IA.
3. Faça a IA perguntar se pode encaminhar um orçamento.
4. Responda `sim, por favor`.
5. Confirme o alerta na conversa, a tarefa no CRM, o contador no dashboard e a marcação no card.
6. Clique em **Marcar orçamento atendido** e confirme que a pendência desaparece.
