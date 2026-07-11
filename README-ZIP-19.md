# RS Connect — ZIP 19

## Onboarding do Cliente + Construtor de Prompt

Este pacote adiciona uma configuração inicial mais guiada para o cliente, mantendo as integrações técnicas sob responsabilidade da RS Connect.

## Principais entregas

- Tela **Configuração inicial** redesenhada.
- Cards de status: empresa, WhatsApp, assistente e integrações RS.
- Cliente pode preencher dados do negócio e gerar um prompt de atendimento.
- Prompt final fica editável antes de salvar.
- O prompt é salvo no agente padrão da empresa.
- A RS Connect continua responsável por n8n, webhooks, credenciais, gateways e integrações sensíveis.
- Texto e telas sem emojis, usando linguagem profissional.

## Atualização

1. Suba os arquivos no GitHub.
2. Faça redeploy no EasyPanel.
3. Execute no Adminer:

```sql
database/migrations/018_onboarding_prompt_builder.sql
```

4. Limpe o cache do navegador com `Ctrl + F5`.

## Como usar

Cliente:

1. Acessa **Configuração inicial**.
2. Confere dados da empresa.
3. Seleciona a instância de WhatsApp cadastrada pela RS Connect.
4. Preenche o construtor de prompt.
5. Clica em **Gerar prompt**.
6. Revê o prompt final e salva o assistente.

RS Connect:

1. Cadastra ou vincula a instância Evolution.
2. Configura credenciais de IA, se necessário.
3. Configura fluxos n8n por empresa.
4. Valida webhooks, gateways e automações externas.

## Observação importante

O cliente tem autonomia para configurar o comportamento do assistente, mas não tem acesso aos fluxos n8n, tokens, URLs sensíveis e chaves de API.
