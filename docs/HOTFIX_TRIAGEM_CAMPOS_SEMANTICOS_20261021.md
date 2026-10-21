# RS Connect — Hotfix: integridade de respostas de triagem (21/10/2026)

## Escopo

Base: `rs-connect-hotfix-lembretes-apenas-confirmados-completo.zip` (inclui correção de triagem Sim/Não e lembretes apenas de compromissos confirmados).

Problema reproduzido na conversa de atendimento fora do expediente:

- `Telefone para contato` recebia `Gostaria de agendar uma consulta.`;
- `É a sua primeira consulta?` recebia `Bom dia, Sim seria pra mim`;
- a pergunta podia ser repetida porque o campo era inválido ou porque um valor de outra etapa foi associado ao campo pendente;
- o painel apresentava a triagem **atual da conversa** mesmo em compromisso já confirmado.

## Mudanças

- `AgentTriageService`: valida campos configurados como telefone pelo rótulo/pergunta, independentemente de o editor ter cadastrado o tipo `text`; só aceita telefone com 10 a 13 dígitos, normalizando para dígitos. A resposta de intenção de agendar não vira telefone.
- `AgentTriageService`: campos Sim/Não (inclusive legados com `field_type=text`) aceitam somente respostas binárias inequívocas; resposta `seria pra mim` não entra na pergunta `primeira consulta`.
- `AgentTriageService`: trata `seria para mim` no cursor `is_for_self`, e revalida informações textuais incorretas de sessões antigas nos próximos turnos, reabrindo o campo pendente sem deslocar a resposta para outro campo.
- `CalendarController`/view: a visualização de triagem detecta valores incompatíveis com o campo e mostra `Resposta incompatível com este campo — conferir com a equipe`, sem modificar dados do banco. O cartão passa a explicar que esses dados são o estado atual da conversa, **não um snapshot congelado na aprovação**.

## O que não foi alterado

- Agendamentos e capacidade de horários publicados;
- Confirmação e aprovação humana;
- Envio de lembretes (hotfix anterior preservado);
- Fila fora do expediente;
- Banco de dados: nenhuma migration nem exclusão de dados.

## Implantação

1. Realize backup de arquivos e banco de dados.
2. Aplique o patch somente se a versão instalada corresponde ao pacote completo da correção de lembretes de 20/10/2026. Se a instalação já possui alterações posteriores não incluídas no pacote, compare as diferenças antes de sobrescrever.
3. Reinicie/limpe OPcache conforme infraestrutura e valide a sintaxe com `php -l` para os arquivos alterados.
4. Teste em uma empresa de homologação: na Ordem, configure `Atendimento para quem`, `Telefone para contato` e `É a sua primeira consulta?`.
5. Envie `Bom dia, sim seria pra mim` à etapa de atendimento para si, depois `Gostaria de agendar uma consulta` quando o campo esperado for telefone. Nenhum desses textos deverá preencher telefone ou primeira consulta.
6. Informe um telefone real e depois `Sim, minha primeira consulta`: o cursor deve avançar sem repetir o mesmo campo.
7. Confira o cartão do agendamento: informações antigas incompatíveis deverão ser sinalizadas, sem tentar corrigi-las ou mascará-las como telefone.
8. Repita um teste de retomada fora do expediente e um de pré-agendamento aguardando aprovação, verificando que não há lembrete enviado prematuramente.

## Limites de homologação

- Os testes realizados são locais e **não** comprovam o comportamento de múltiplos workers em MySQL e WhatsApp real.
- O cartão ainda consulta `conversation_triage_sessions`, portanto pode refletir respostas posteriores ao compromisso. Para resolver definitivamente, recomenda-se uma migration que armazene um snapshot de triagem por agendamento, com momento e versão da configuração, preenchido **no momento da pré-reserva/confirmacão**, além de trilha de auditoria para alterações posteriores.
- Não executar UPDATE/DELETE genérico em registros históricos. Corrija os campos de agendamentos antigos somente após conferência humana de cada caso.
