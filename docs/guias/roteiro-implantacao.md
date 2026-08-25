# Roteiro de implantação

1. Fazer backup do banco, `.env` e uploads.
2. Subir os arquivos da versão aprovada.
3. Preservar as variáveis existentes.
4. Executar migrations pendentes em ordem.
5. Rodar diagnósticos SQL.
6. Fazer rebuild/redeploy completo.
7. Validar login de Super Admin e admin do cliente.
8. Validar Evolution, webhook, OpenAI e cron.
9. Criar uma instância de teste.
10. Conectar QR Code e vincular assistente.
11. Executar roteiro de homologação.
12. Registrar evidências e plano de rollback.

## Rollback

- Restaurar arquivos anteriores.
- Restaurar banco somente se uma migration destrutiva tiver sido executada.
- Não reutilizar QR Code expirado.
- Registrar o motivo do rollback e os logs associados.
