# Guia de governança e orçamento de IA por empresa

## Objetivo

A v36.19.2 permite controlar financeiramente a IA custeada pela RS Connect sem transformar o orçamento em um bloqueio geral da operação.

A política considera somente chamadas ao provedor registradas com `credential_owner = rs_connect` e custo estimado em USD. Portanto, continuam disponíveis mesmo quando a política bloqueia IA RS:

- atendimento humano;
- respostas locais;
- cache exato;
- mensagens e operação do WhatsApp;
- IA com credencial própria do cliente.

## Onde configurar

1. Acesse **Automação e integrações → Consumo OpenAI**.
2. Selecione a empresa no filtro superior.
3. Na seção **Orçamento e proteção de consumo**, ative a política.
4. Informe orçamento, thresholds e ações.

## Níveis

- **Atenção**: primeiro aviso. Pode apenas alertar ou forçar o modo Econômico.
- **Crítico**: eleva a severidade do alerta para a equipe administrativa.
- **Limite**: executa a ação final configurada.

## Ações

### Somente alertar

Mantém o atendimento exatamente como está e gera alertas.

### Forçar modo Econômico

As novas chamadas com credencial RS usam temporariamente o perfil Econômico, reduzindo histórico, base enviada e limite de saída. A configuração original do assistente não é alterada no banco.

### Bloquear IA RS

Impede apenas uma nova chamada ao provedor custeada pela RS Connect. A mensagem do cliente permanece preservada para atendimento humano ou outra estratégia disponível.

## Boas práticas

- Não ative bloqueio automático antes de validar o custo atribuído por empresa.
- Comece com 80% em Econômico e 100% apenas como alerta.
- Compare custo interno com o custo oficial da OpenAI.
- Verifique modelos sem tarifa antes de tomar decisões com base no orçamento.
- Use credencial própria quando o contrato do cliente definir que ele custeia diretamente a IA.

## Auditoria

Cada alteração da política gera `ai.budget_policy.updated` no histórico de auditoria. Os thresholds efetivamente atingidos são preservados em `ai_budget_threshold_events`, evitando alertas duplicados no mesmo ciclo e orçamento.
