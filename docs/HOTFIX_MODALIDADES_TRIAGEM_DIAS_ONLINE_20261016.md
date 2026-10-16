# RS Connect — Hotfix de modalidade, triagem e dias online (16/10/2026)

## Base

Este pacote parte de `rs-connect-hotfix-agenda-data-periodo-completo.zip` (Agenda Etapa 5 + hotfix da continuidade de data e período). **Não contempla alterações desconhecidas feitas diretamente em produção depois desse pacote.** O patch substitui somente arquivos listados abaixo. Faça backup do banco e dos arquivos antes da implantação.

## Sintoma e diagnóstico

Na Ordem do Atendimento, o campo **Modalidade** foi desmarcado. Apesar disso, mensagens como “Gostaria de agendar uma consulta para o dia 17/10” recebiam “Antes de confirmar, preciso saber a modalidade ...”.

Causa: `CalendarConversationService::guardConfirmationWithoutSelectedSlot()` considerava a palavra “agendar” confirmação de uma reserva anterior sem vaga selecionada e exigia modalidade mesmo fora da etapa de coleta. O fallback de `AiResponseContractService` também exigia modalidade apenas por existir um pré-agendamento sem essa informação.

## Alterações

1. `CalendarConversationService`: separa **novo pedido de agendamento** de **confirmação**, sem permitir confirmação sem vaga real. Modalidade só é exigida pela guarda quando o modo de atendimento é de escolha e a Ordem exige modalidade antes da consulta.
2. `AiResponseContractService`: fallback respeita o mesmo requisito; não cria perguntas adicionais fora do fluxo.
3. `ServiceModalityConfiguration`: adiciona `online_days` ao contrato de leitura/gravação; salva em `conversation_behavior.modalities.online.allowed_days` sem adicionar tabela nova.
4. `ExpressOnboardingRequest`: valida dias online (seg–dom) e mantém compatibilidade com payload antigo sem `online_days` (nesse caso, sem restrição de dia online).
5. `Meu atendimento`: dias online, local presencial, dias presenciais e textos de orientação separados; a edição administrativa avançada continua somente leitura quando a empresa gerencia modalidades.
6. `AgentConversationBehaviorService`: dias online entram no contexto operacional. Vagas publicadas também respeitam as restrições explícitas de dias da empresa; horário publicado não burla dias permitidos. Agenda ainda precisa validar disponibilidade, capacidade, antecedência e aprovação.
7. Cache-busting do JavaScript do painel.

## Contrato comercial e modalidades

Propriedades opcionais em `service_modalities`:

```json
{
  "mode": "both",
  "online_channel": "Google Meet",
  "online_days": ["mon", "wed", "fri"],
  "online_message": "Atendimentos online pelo Meet. O link será enviado após a confirmação.",
  "presencial_location": "Rua Exemplo, 100, Centro",
  "presencial_days": ["tue", "thu"],
  "presencial_message": "Atendemos na unidade às terças e quintas."
}
```

A lista `online_days` vazia significa **sem restrição adicional por dia de modalidade**: somente horários efetivamente disponíveis na Agenda podem ser ofertados. Não cria novas vagas. A lista presencial permanece obrigatória quando o modo presencial está ativo, conforme contrato existente.

**Dois modos ativos:** desmarcar a etapa “Modalidade” permite a consulta de horários sem obrigar essa pergunta antecipadamente; isso **não autoriza** escolher arbitrariamente a modalidade ou confirmar reserva sem identificar a vaga selecionada. Se quiser que o cliente escolha entre online e presencial antes da consulta, marque a etapa “Modalidade” antes da ação de agenda. **Modo único:** o sistema deve assumir a única modalidade cadastrada e não perguntar.

## Instalação

1. Backup do banco e arquivos; aplicar em homologação primeiro.
2. Extrair o patch na **raiz** do RS Connect (`/var/www/html`) preservando caminhos. Não usar em instalação baseada em versão anterior à base declarada.
3. Validar sintaxe PHP (`php -l`), reiniciar o PHP/limpar OPcache se aplicável, recarregar navegador com cache atualizado.
4. Em **Meu atendimento → Regras de atendimento → Como acontecem os atendimentos?**, definir modo, online, dias online, presencial, endereço, dias presenciais, textos. Salvar e recarregar.
5. Em **Ativação Assistida → Ordem do atendimento**, confirmar se o campo Modalidade é ou não uma coleta ativa antes da Agenda.
6. Repetir a conversa em atendimento de teste: “Gostaria de agendar para 17/10”, depois “pela manhã”. Deve chegar à consulta das vagas reais conforme demais perguntas obrigatórias; não pedir modalidade se não estiver exigida.
7. Testar dia proibido online, dia permitido presencial, confirmação de pré-reserva, agenda lotada, limite de antecedência e aprovação humana. Verificar que reservas não são confirmadas sem uma vaga válida.

## Testes

Passaram: `tests/Feature/modalities-days-workflow-20261016.php`, `service-modalities-single-source-regression.php`, `calendar-modality-before-availability-smoke.php`, `agenda-date-period-continuation-20261016-smoke.php`, `triage-agenda-two-scenarios-regression-smoke.php`, `agenda-reservation-safety-phase2-smoke.php`. Sintaxe PHP e JS validadas.

Um teste legado genérico `agent-conversation-behavior-v36310-smoke.php` ainda falha em quatro verificações de texto/versionamento obsoletas na base anterior; não foi ajustado para forçar aprovação.

**Limite:** não foi possível executar integração real com MySQL, Evolution ou WhatsApp. Recomendado homologar antes de produção.
