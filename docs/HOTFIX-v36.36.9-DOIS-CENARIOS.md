# RS Connect 36.36.9 — Correção controlada dos dois cenários de triagem e agenda

Base: pacote anterior `rs-connect-vps-ready-fix-guard-agenda-ia.zip`. O patch **não modifica bancos existentes**, não cria migrations e mantém a barreira determinística de segurança antes do modelo.

## Correções do hotfix

1. **Apresentação na primeira resposta determinística:** o agente se identifica com o nome público antes da pergunta de triagem, mesmo quando a IA generativa ainda não foi chamada. Respeita `ai_greeting_mode=disabled`. Uma resposta posterior não repete a apresentação. Templates com placeholders sem contexto usam introdução segura.
2. **Demanda curta e perguntas informativas:** a triagem aceita uma resposta explícita curta, como `Ansiedade`, e sincroniza esse dado com o estado usado pelas regras dos grupos; não interpreta a pergunta `Como funciona a terapia?` como se fosse uma demanda clínica informada. A IA recebe instrução para abordar preço e funcionamento antes de fazer a próxima pergunta, sem inventar valores.
3. **Continuação restrita da agenda:** quando a triagem está coletando a intenção de agendamento, uma mensagem atual com dia/período/modalidade preserva o contexto e pode criar ou atualizar o pré-agendamento depois da validação. Mensagens comuns como `Quanto é?` não reativam intenções antigas; o estado do fluxo é relido após a sincronização da demanda.
4. **Período NÃO é horário exato:** `terça à tarde` pode gerar pesquisa e opções reais, mas nunca faz pré-reserva automática das 14h, usadas internamente apenas para começar a busca. A seleção automática exata continua permitida se o cliente informar explicitamente `terça às 14h` e o horário estiver realmente livre.

## Limites da validação automatizada

Foram executados testes puros de 17 cenários e testes focados de regressão, além de lint dos PHP alterados. Não foi possível reproduzir a configuração da empresa e os dados reais do MySQL/WhatsApp neste ambiente. **Homologue em staging com um contato de teste antes de publicar**, especialmente se a empresa utiliza o modo Formulário para mensagens de agenda, regras personalizadas de grupo e profissional padrão.

## Homologação no ambiente de testes

**Cenário A — Mensagens fracionadas:**

- Contato novo envia `Olá, bom dia`, `Como funciona a terapia?` e `Tem horário? Qual é o valor?` em mensagens próximas.
- A primeira resposta identifica a assistente; se o valor não estiver configurado, não inventa preço. A pergunta sobre funcionamento não pode preencher automaticamente `brief_demand`.
- Para um novo lead, obtenha a demanda ou recusa, a idade, a modalidade e a preferência de dia/período conforme as regras reais do modelo da empresa.
- Se o lead disser `terça à tarde`, a tela de diagnóstico deve registrar a preferência de **período**. Deve oferecer horários reais e só marcar **Horário escolhido** quando o contato selecionar uma opção, salvo quando ele informou horário exato e houve validação real.

**Cenário B — Triagem concluída antes da consulta:**

- Novo lead pede agendamento, responde `Ansiedade` à pergunta de demanda, informa `Presencial`, prefere `terça à tarde` e responde `Através de uma amiga` à pergunta sobre origem, caso essa etapa esteja ativa.
- Verifique que a demanda curta aparece como `collected` no estado do fluxo e que a preferência não desaparece após a pergunta de origem. Com a agenda habilitada e regras atendidas, deve ser criado/atualizado um registro em `calendar_appointments` e uma consulta em `calendar_availability_requests`.
- Ao receber as opções, o cliente escolhe uma. Somente então o sistema registra um horário exato e apresenta `Aguardando aprovação` se a configuração exigir validação humana.

**Não regressão:** após um pré-agendamento, envie `Quanto custa?` ou `Obrigada`. Isso não pode criar uma segunda consulta de disponibilidade nem gerar `calendar.pre_schedule_unhandled` sem nova preferência ou pedido real de agenda.

Se um ambiente de staging não reproduzir estes resultados, consulte os eventos técnicos da conversa e o diagnóstico de agenda. O log `calendar.pre_schedule_unhandled` sem um erro SQL específico pode sinalizar configuração de grupo, agenda desativada, pendência de modalidade ou dados inconsistentes anteriores; não pressuponha falha de credencial de IA.

## Atualização e reversão

Aplique os arquivos sobre a mesma base usada na atualização anterior; mantenha cópia integral do pacote anterior e backup dos dados. Reinicie o PHP/OPcache conforme o mecanismo de deploy. Se houver regressão, restaure o código da versão anterior; este patch não altera schema ou dados por migration. Solicitações antigas incompletas **não são reprocessadas automaticamente**: faça primeiro uma conversa de teste nova.
