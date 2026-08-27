# ENT-028 / PA-002 — Validação técnica v36.20.13

## Escopo validado

- Produção em modo fail-closed para webhooks.
- Assinaturas específicas de PagBank/PagSeguro, Stripe e Mercado Pago.
- Token oficial do Asaas no header.
- Token e HMAC interno para callbacks do n8n.
- Token Evolution apenas por header, sem exposição na URL nem na interface.
- Timestamp, replay e idempotência persistente.
- Sanitização de logs e respostas de erro.
- Configuração e geração de cobrança PagBank/PagSeguro por checkout.
- Preservação do saneamento de `tests/` da ENT-026.

## Validações executadas

| Validação | Resultado |
|---|---|
| PHP lint | 306 arquivos, nenhum erro |
| JavaScript `node --check` | 3 arquivos, nenhum erro |
| JSON decode | 54 arquivos, nenhum erro |
| Bootstrap | `BOOTSTRAP_OK` |
| Smoke ENT-026 | 6 verificações aprovadas |
| Smoke ENT-028 | 19 verificações aprovadas |
| Suíte completa | 76 aprovados, 9 falhas históricas, 85 total |
| Estrutura de `tests/` | Unit, Integration, Feature, Contract e Support |
| Token Evolution em URL | Não encontrado no controller nem na view |

## Falhas históricas preservadas

As nove falhas restantes já pertenciam ao baseline da v36.20.12. Elas se concentram em:

- expectativa antiga da fila humana fora do horário;
- arquivos temporários legados;
- documentos históricos `INSTRUCOES-v*.md` ausentes no pacote original;
- verificações antigas de cache/versão associadas a essas documentações.

A ENT-028 adicionou um novo smoke test e atualizou o teste de endurecimento anterior para reconhecer a nova camada centralizada, sem ampliar a quantidade de falhas do baseline.

## Dependências externas não executadas

A homologação completa depende de ambiente com:

- MySQL e migration `087_webhook_security_events.sql` aplicada;
- Evolution API ativa;
- n8n com headers HMAC configurados;
- credenciais Sandbox do PagBank/PagSeguro;
- HTTPS público para recebimento dos callbacks.

## Ordem obrigatória de implantação

1. Fazer backup do banco e da pasta atual.
2. Aplicar `database/migrations/087_webhook_security_events.sql`.
3. Atualizar o `.env` com os segredos exigidos.
4. Publicar o código da v36.20.13.
5. Reaplicar a configuração dos webhooks das instâncias Evolution.
6. Atualizar o callback do n8n com token, timestamp e assinatura.
7. Homologar cobrança PagBank/PagSeguro primeiro no Sandbox.
