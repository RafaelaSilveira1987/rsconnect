# RS Connect v36.23.2

## O que foi corrigido

1. **Horário do agendamento:** as datas da agenda são horários locais do compromisso. O WhatsApp agora exibe exatamente o mesmo horário da tela.
2. **Envio automático:** notificações imediatas de novo agendamento, confirmação, cancelamento, remarcação e orçamento são processadas no momento do evento, sem depender do botão manual.
3. **Configurações separadas:** o histórico permanece em `/notifications` e as regras ficam em `/settings/notifications`.

## Atualização

Não há migration nova. Substitua os arquivos e reinicie/reimplante o serviço.

## Agendador

O envio imediato não depende mais do cron. Para lembretes futuros e orçamento atrasado, mantenha:

```bash
php /var/www/html/bin/process-notifications.php
```

a cada minuto no EasyPanel.

## Teste

- Crie um compromisso às 09:00 e confirme que o WhatsApp mostra 09:00.
- Não clique em “Processar fila agora”; o aviso imediato deve ser enviado sozinho.
- Acesse **Config. notificações** no menu Administração para alterar as regras.
