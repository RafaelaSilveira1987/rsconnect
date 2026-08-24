# Homologação — RS Connect 36.6.35

## Banco

- [ ] migration 062 aplicada;
- [ ] tabela `ai_prompt_studio_drafts` existente;
- [ ] tabela `ai_agent_prompt_versions` existente;
- [ ] prompts atuais registrados como versão inicial.

## Prompt Studio

- [ ] geração funciona no perfil cliente;
- [ ] geração funciona no Super Admin;
- [ ] prompt contém identidade, objetivo, público e regras de segurança;
- [ ] empresa sem agenda recebe proteção para não oferecer horários;
- [ ] conflito de atendimento 24h x horário técnico gera alerta;
- [ ] confirmação automática incompatível com aprovação humana gera alerta;
- [ ] prompt continua editável antes do salvamento.

## Versionamento

- [ ] criação do agente gera uma versão;
- [ ] edição manual gera nova versão;
- [ ] histórico exibe autor, data e origem;
- [ ] restauração atualiza o agente;
- [ ] restauração cria nova versão de auditoria.

## Onboarding

- [ ] etapa 6 permite acessar o Prompt Studio;
- [ ] chamada AJAX do Prompt Studio não é bloqueada pelo acesso progressivo;
- [ ] agente criado conclui a etapa do onboarding;
- [ ] teste final continua liberado depois da criação.
