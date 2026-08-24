# Homologação final — RS Connect 36.6.7

## Credenciais e tokens
- [ ] Callback global do n8n sem token + fluxos ativos aparece como **Recomendado**, com explicação de segurança.
- [ ] Cron da fila da IA sem token aparece como **Recomendado** quando a rotina está habilitada.
- [ ] Itens sem uso permanecem **Opcional**.

## Gateways e pagamentos
- [ ] Gateway ativo sem evento nos últimos 7 dias aparece como **Sem evidência**, não **Atenção**.
- [ ] Falha real de pagamento recente continua gerando **Atenção**.

## Assistente de IA
- [ ] `status=inactive` não gera crítico apenas por estar desligado.
- [ ] `auto_reply_enabled=0` não gera crítico apenas por estar desligado.
- [ ] O card informa `Desativado pelo cliente`, `Desativado pela equipe RS` ou `Desativado manualmente` quando possível.
- [ ] Falhas históricas são exibidas nos detalhes, mas não elevam severidade de um assistente manualmente desligado.
- [ ] Assistente ativo com 3+ falhas consecutivas aparece como **Crítico / Indisponível por erro**.
- [ ] Assistente ativo sem credencial ou sem WhatsApp continua crítico.
- [ ] Ao normalizar/desativar intencionalmente, incidente crítico anterior é resolvido na próxima verificação.
