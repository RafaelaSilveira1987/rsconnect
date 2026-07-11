# Atualizar do ZIP 16 para o ZIP 17

1. Suba os arquivos do ZIP 17 no GitHub.
2. Faça redeploy do serviço `rsconnect` no EasyPanel.
3. No Adminer, execute:

```sql
database/migrations/016_reports_realtime_conversations.sql
```

4. Acesse o sistema e pressione `Ctrl + F5`.
5. Teste:
   - `/conversations`
   - `/reports`

Não há alteração destrutiva no banco. A migration apenas adiciona a permissão `reports.view`.
