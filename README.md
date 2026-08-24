# RS Connect - v36.18.2

Esta versão corrige o vínculo entre assistentes de IA e conexões WhatsApp. Ao criar um novo canal, o RS Connect vincula automaticamente o único assistente ativo ou o fallback geral da empresa. Também passa a ser possível selecionar manualmente um ou vários canais dentro da edição do próprio assistente.

## Atualização

- Não exige nova migration.
- Preserve o `.env`.
- Faça rebuild/redeploy e `Ctrl + F5`.
- Acesse **Assistentes de IA**, marque a conexão em **Canais WhatsApp** e clique em **Salvar configurações**.
- Novas instâncias são vinculadas automaticamente quando a empresa possui um único assistente ativo ou um único fallback geral.

As migrations anteriores até `079_ai_efficiency_phase2_and_report_cleanup.sql` continuam obrigatórias conforme a origem da instalação.
