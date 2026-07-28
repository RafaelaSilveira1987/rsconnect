# RS Connect 36.6.35 — Prompt Studio e versionamento de instruções

## Objetivo

Transformar a criação do agente em um processo guiado, sem exigir que o cliente saiba escrever prompts do zero.

## O que mudou

- Prompt Studio integrado ao cadastro de agentes;
- questionário sobre objetivo, público, serviços, leads, clientes, agenda, transferência e restrições;
- geração estruturada sem consumo obrigatório de API;
- validação de conflitos com as regras operacionais do RS Connect;
- prompt final editável antes de salvar;
- rascunho do Prompt Studio;
- histórico de versões por agente;
- restauração de versão anterior;
- auditoria das gerações e restaurações.

## Atualização

1. Faça backup do banco e do código atual.
2. Publique `rs-connect-vps-ready-36.6.35.zip`.
3. Execute:

```sql
SOURCE database/migrations/062_prompt_studio_and_versions.sql;
```

4. Faça o redeploy da aplicação.
5. Atualize o navegador com `Ctrl + F5`.

## Primeiro teste

1. Entre em uma empresa que já tenha uma conexão WhatsApp.
2. Abra **Assistentes de IA**.
3. Clique em **Novo assistente**.
4. Preencha identidade, objetivo, público, serviços e regras.
5. Clique em **Gerar prompt estruturado**.
6. Revise os alertas e o prompt final.
7. Crie o assistente.
8. Abra o histórico do prompt e confirme que a primeira versão foi registrada.
