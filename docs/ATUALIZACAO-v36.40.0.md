# Atualização RS Connect 36.40.0

Esta versão normaliza a preferência de agenda e a forma de atendimento em uma camada única, genérica e orientada pela configuração da empresa.

## O que muda

- pedidos com `vaga/vagas`, dia, período e horário são interpretados pela mesma camada;
- números soltos de idade não viram horário;
- uma troca de forma de atendimento invalida opções antigas antes de uma nova busca;
- `sem modalidade`, `modalidade única` e `cliente escolhe` obedecem à configuração do tenant, independentemente do nicho;
- frases como `trocar de presencial para online` usam o destino correto;
- compromissos já confirmados reconhecem pedido de troca de modalidade sem sobrescrever silenciosamente o compromisso atual;
- a triagem e o pré-agendamento mantêm o mesmo estado de modalidade/preferência;
- consulta por dia/período fica estritamente no dia solicitado. Uma vaga publicada na quarta não é devolvida quando o contato pediu quinta.

## Homologação

Valide ao menos um tenant de cada tipo: sem modalidade, modalidade única e escolha entre modalidades. Na Agenda publicada, publique horários em dias diferentes e confirme que a conversa retorna somente o dia/período solicitado. Teste também troca de modalidade antes e depois da exibição de opções.

Não há migration nova. A migration obrigatória permanece `122_calendar_client_communications.sql`.
