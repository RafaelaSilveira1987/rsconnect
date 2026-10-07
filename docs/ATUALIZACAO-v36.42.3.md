# Atualização RS Connect 36.42.3

## Objetivo

Garantir que lembretes configurados na Agenda sejam enviados automaticamente sem depender de execução manual do processador e restaurar a ação **Remarcar** para compromissos ativos.

## Lembretes

A regra continua totalmente configurável por empresa em **Agenda → Configurações → Comunicação e confirmação do agendamento**:

- ativar/desativar lembrete;
- quantidade e unidade de antecedência;
- mensagem do lembrete;
- pedido separado de confirmação de presença.

A versão adiciona somente um worker técnico para consumir a fila. Ele não contém regra fixa de 4 horas, texto fixo ou horário fixo. Ao iniciar, ele também reconcilia os compromissos futuros das empresas que já possuíam a automação ativada, para não exigir que a configuração seja salva novamente depois do deploy.

A reconciliação foi ajustada para não comparar o horário local do compromisso diretamente com o relógio UTC do MySQL; a validação final continua usando o fuso do próprio agendamento.

Por padrão o container processa as filas a cada 30 segundos. Variáveis opcionais:

```env
RS_NOTIFICATION_WORKER_ENABLED=true
RS_NOTIFICATION_WORKER_INTERVAL_SECONDS=30
```

Se a instalação já usa um scheduler externo exclusivo para `php bin/process-notifications.php`, o worker interno pode ser desligado com `RS_NOTIFICATION_WORKER_ENABLED=false`.

## Remarcação

A ação **Remarcar** volta a aparecer para compromissos ativos/confirmados na lista da Agenda. Compromissos já concluídos, cancelados ou recusados permanecem protegidos contra alteração de status por esse botão.

## Deploy

É necessário reconstruir/reiniciar o container para que o novo comando de inicialização passe a executar o worker interno.

## Banco de dados

Não há migration nova. Permanece obrigatória:

`124_calendar_slot_capacity_mode.sql`

## Homologação sugerida

1. Ative **Lembrete automático** e configure, por exemplo, 10 minutos antes.
2. Confirme um compromisso futuro com WhatsApp válido.
3. Aguarde o horário configurado sem clicar em **Processar agora** e sem acionar n8n manualmente.
4. Confirme que o texto recebido é exatamente o configurado para a empresa.
5. Abra um compromisso confirmado e valide a presença do botão **Remarcar**.
