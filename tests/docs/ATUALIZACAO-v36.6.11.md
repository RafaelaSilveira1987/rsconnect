# Atualização RS Connect 36.6.11

## Escopo

- uso total de IA visível, incluindo credencial própria;
- franquia RS permanece separada e só considera IA custeada pela RS Connect;
- cards de uso com explicações em linguagem operacional;
- histórico de notificações antes das preferências;
- correção da visibilidade de menus por empresa, incluindo Privacidade/LGPD.

## Banco de dados

Não há migration nova nesta versão. A última migration necessária continua sendo:

```sql
SOURCE database/migrations/053_ai_quota_limit_repair.sql;
```

## Após o deploy

1. Abra **Planos e cobrança → Ver uso e histórico** e confirme os três números: total de IA, franquia RS e credencial própria.
2. Abra uma empresa em **Menus do cliente**, marque `Privacidade/LGPD` como Menu + Acesso e salve.
3. Entre como usuário da empresa com `privacy.view`: o item deve aparecer no menu lateral.
4. Abra **Notificações** e confirme que o histórico vem antes das preferências.
