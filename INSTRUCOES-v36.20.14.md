# RS Connect v36.20.14 — Health checks seguros

## Endpoints

### Liveness público

```text
GET /health/live
```

Resposta esperada:

```json
{"status":"ok"}
```

Esse endpoint confirma apenas que o processo PHP e o roteamento da aplicação estão respondendo. Não consulta banco, storage ou integrações.

### Readiness público

```text
GET /health/ready
```

Quando a aplicação está pronta:

```json
{"status":"ok"}
```

Código HTTP: `200`.

Quando uma dependência crítica está indisponível:

```json
{"status":"unavailable"}
```

Código HTTP: `503`.

O endpoint público nunca apresenta host, nome do banco, usuário, credenciais, exceções ou caminhos internos.

### Diagnóstico detalhado protegido

```text
GET /health/ready/details
```

Requer sessão autenticada de **Super Admin RS**. O retorno contém somente o estado genérico dos componentes `database`, `storage` e `application_key`, sem valores de configuração ou mensagens de exceção.

## Homologação

```bash
curl -i https://SEU_DOMINIO/health/live
curl -i https://SEU_DOMINIO/health/ready
```

Para validar falha de readiness sem afetar produção, realize o teste em ambiente de homologação removendo temporariamente uma dependência crítica e confirme o HTTP `503` com corpo resumido.

## EasyPanel / balanceador

- Liveness: `/health/live`
- Readiness: `/health/ready`
- Intervalo recomendado: 30 segundos
- Timeout recomendado: 5 segundos

## Banco de dados

Não existe migration nova nesta entrega. A migration obrigatória permanece:

```text
database/migrations/088_payment_reconciliation_schema_compat.sql
```
