# Homologação — RS Connect 36.6.34.2

## 1. Banco e versão

- [ ] Migration 060 aplicada.
- [ ] Migration 061 aplicada.
- [ ] Status do sistema mostra migration base 061.
- [ ] Login não duplica `APP_URL` após sessão expirada.

## 2. Nova empresa sem Agenda inteligente

- [ ] Criar uma empresa nova.
- [ ] Concluir cadastro, LGPD e regras de atendimento.
- [ ] Na Etapa 4, visualizar três opções de agenda.
- [ ] Agenda inteligente aparecer como não liberada.
- [ ] Não ser possível selecionar Agenda inteligente pelo navegador/formulário.

## 3. Agenda interna

- [ ] Selecionar Agenda interna do RS Connect.
- [ ] Ativar segunda a sexta e cadastrar horários.
- [ ] Ativar sábado com horário menor, por exemplo 08:00–12:00.
- [ ] Salvar e avançar para WhatsApp.
- [ ] Confirmar no banco/configuração: `enabled=1`, `use_n8n=0`, `use_internal_fallback=1`.
- [ ] Confirmar que criação/sincronização Google está desativada.
- [ ] Testar um horário livre.
- [ ] Criar um compromisso e confirmar que o mesmo horário deixa de ser oferecido.
- [ ] Confirmar zero execução de workflow de Agenda no n8n.
- [ ] Confirmar zero evento no Google Calendar.

## 4. Sem agenda

- [ ] Selecionar Não utilizar agenda.
- [ ] Concluir a etapa como dispensada.
- [ ] Confirmar que o agente não consulta nem registra disponibilidade.

## 5. Liberação pelo Super Admin

- [ ] Abrir Configurações da empresa como Super Admin.
- [ ] Alterar Agenda inteligente para Em configuração.
- [ ] Confirmar que continua bloqueada para o cliente.
- [ ] Alterar para Liberada e homologada.
- [ ] Confirmar que a opção se torna selecionável no onboarding.
- [ ] Confirmar que os campos técnicos de n8n/Google não aparecem para o cliente.

## 6. Regressões

- [ ] Teste gratuito de 7 dias continua calculando fim e primeira cobrança.
- [ ] Empresas antigas não são obrigadas a repetir o onboarding.
- [ ] Busca de contatos continua funcionando.
- [ ] Recuperação pós-horário da Agenda continua funcionando.
