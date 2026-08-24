# Homologação final — RS Connect 36.6.12

## Estrutura

- [ ] Migration `054_ai_metrics_and_delivery_telemetry.sql` aplicada.
- [ ] Status do sistema mostra `RS Connect 36.6.12`.
- [ ] Check `Telemetria técnica da IA` está OK.

## Mensagens x IA

- [ ] Mensagem recebida aumenta **Mensagens movimentadas**, mas não aumenta Interações de IA.
- [ ] Resposta humana aumenta Mensagens movimentadas, mas não aumenta Interações de IA.
- [ ] Mensagem automática fixa aumenta Mensagens movimentadas, mas não aumenta franquia de IA.

## Resposta automática com IA RS

- [ ] Resposta efetivamente entregue aumenta Interações automáticas em 1.
- [ ] Aumenta Franquia IA RS em 1.
- [ ] Registra ao menos 1 chamada ao provedor.
- [ ] Tokens aparecem quando o provedor os retorna.

## Credencial própria

- [ ] Resposta entregue aumenta Interações automáticas em 1.
- [ ] Aumenta IA com credencial própria em 1.
- [ ] Não aumenta Franquia IA RS.
- [ ] Chamadas e tokens continuam sendo registrados.

## Falhas

- [ ] Falha do provedor não aumenta interação comercial.
- [ ] Resposta gerada e descartada após takeover humano não aumenta interação comercial.
- [ ] Falha na Evolution após geração não aumenta interação comercial.
- [ ] Nos cenários acima, a telemetria técnica já conhecida permanece registrada.

## Sugestão manual

- [ ] Gerar sugestão registra chamada/tokens no painel técnico.
- [ ] Sugestão não aumenta Interações automáticas.
- [ ] Sugestão não reduz Franquia IA RS.

## Painel Super Admin

- [ ] Mostra chamadas ao provedor.
- [ ] Mostra tokens de entrada, saída, total e cache.
- [ ] Mostra falhas técnicas.
- [ ] Tabela por assistente separa RS Connect x Cliente.
- [ ] Sem `AI_COST_RATES_JSON`, custo aparece como não configurado/—.
- [ ] Com tarifa válida configurada, custo estimado aparece sem afetar a franquia.
- [ ] Custo de credencial RS e custo de referência da credencial própria aparecem separados.

## Regressão

- [ ] Cooldown continua sendo respeitado.
- [ ] Conversa em Humano/Pausado não recebe automação.
- [ ] Recuperação pós-horário continua funcionando.
- [ ] Limites 80/95/100% continuam baseados somente na franquia RS.
