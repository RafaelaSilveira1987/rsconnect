# Atualizar do ZIP 22 para o ZIP 23

1. Envie os arquivos do ZIP 23 para o repositório.
2. Faça redeploy no EasyPanel.
3. Rode no Adminer:

```sql
database/migrations/026_fix_implementation_manual_checklist_table.sql
database/migrations/027_guided_client_onboarding.sql
```

4. Limpe cache com `Ctrl + F5`.
5. Teste como cliente em `/onboarding`.
6. Como Super Admin, acompanhe em `/implantacao`.

Não há módulo de campanhas/disparos neste pacote.
