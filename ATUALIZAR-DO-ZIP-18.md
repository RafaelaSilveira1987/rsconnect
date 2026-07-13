# Atualizar do ZIP 18 para o Hotfix 18.1

1. Substitua os arquivos do projeto pelos arquivos deste pacote.
2. Faça commit/push no GitHub.
3. Faça redeploy no EasyPanel.
4. Execute a migration:

```text
database/migrations/018_pre_schedule_messages_confirmation.sql
```

5. Acesse Configurações da empresa e ajuste as mensagens do pré-agendamento.

## Teste sugerido

1. Ative o pré-agendamento para uma empresa.
2. Envie pelo WhatsApp: `Quero agendar terça à tarde`.
3. Confira se a Agenda mostra preferência de dia e horário.
4. Clique em Aprovar.
5. Confira se o cliente recebeu a mensagem de confirmação no WhatsApp.
