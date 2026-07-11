# Atualizar do ZIP 18 para o ZIP 19

1. Envie os arquivos deste pacote para o repositório.
2. Faça redeploy do serviço `rsconnect` no EasyPanel.
3. No Adminer, execute:

```sql
database/migrations/018_onboarding_prompt_builder.sql
```

4. Acesse como cliente e abra:

```text
Configuração inicial
```

5. Teste o construtor de prompt:

- preencha os dados do negócio;
- clique em **Gerar prompt**;
- edite o prompt final, se necessário;
- salve o assistente.

6. Limpe o cache do navegador com `Ctrl + F5`.
