# RS Connect 36.36.16 — Regras do agente como fonte de verdade

## Problema observado

Em um turno com perguntas de informação e pedido de agenda, a triagem determinística podia avançar para modalidade/preferência sem exigir a demanda mesmo quando a opção **Exigir a demanda antes de consultar a agenda** estava marcada. Além disso, perguntas como **qual o valor?** ou **como funciona?** podiam ficar sem resposta porque a triagem fixa encerrava o turno antes da IA.

A causa era a existência de mais de uma representação da mesma regra: `conversation_behavior`, `tenant_triage_fields`, regras por grupo e contexto enviado ao modelo. Um registro antigo em `tenant_triage_fields` podia manter `brief_demand.required_before_schedule = 0` apesar da configuração amigável estar ativa.

## Correção

- `conversation_behavior[demand]` passa a sobrepor o perfil de triagem em memória antes do Policy Engine.
- Se a demanda estiver marcada como obrigatória, `brief_demand` é tratado como ativo e obrigatório antes da agenda mesmo com dados antigos no banco.
- A camada de pré-agendamento revalida demanda/grupo em toda tentativa, inclusive quando já existe um pré-agendamento em andamento.
- Respostas como `online`, `sim`, idade, dia/período ou perguntas de preço não satisfazem o campo `brief_demand` por engano.
- Em modo híbrido, turnos com pergunta informativa + intenção de agenda podem ir à IA para responder o que foi perguntado, mantendo a agenda bloqueada até a próxima informação obrigatória.
- Cliente/paciente atual continua reaproveitando dados conhecidos, mas a regra global de demanda não é desligada silenciosamente.

## Homologação sugerida

1. Marque **Perguntar a demanda** e **Exigir a demanda antes de consultar a agenda**.
2. Configure valor/pagamento no bloco estruturado do agente.
3. Inicie uma conversa nova com três mensagens rápidas:
   - `Boa tarde`
   - `Queria saber como funciona a terapia por gentileza`
   - `Se tem horário disponível, qual o valor?`
4. A primeira resposta deve responder o que for possível sobre funcionamento/valor e fazer apenas a próxima pergunta obrigatória.
5. Após responder identificação/idade/modalidade, o sistema deve perguntar a demanda antes de consultar qualquer horário.
6. Informe uma demanda curta, por exemplo `Ansiedade`.
7. Somente depois disso informe dia/período e confirme que a agenda oferece horários reais.

Não há migration nova nesta versão.
