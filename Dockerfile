# Validação — RS Connect v36.26.2

## Escopo

Correção do monitor financeiro para impedir que falhas históricas já recuperadas reabram continuamente o alerta `operations.alert.payments`.

## Comportamento validado

- somente gateways atualmente ativos participam da verificação;
- a última falha é comparada com a última confirmação bem-sucedida de cada gateway;
- falha posterior ao último sucesso mantém o estado de atenção;
- sucesso posterior à falha muda o check para `ok`;
- falhas antigas permanecem no histórico sem reabrir incidente;
- ao marcar o incidente financeiro como resolvido, o check financeiro é recalculado imediatamente;
- não existe migration nova.

## Resultados

- 117 de 117 testes de fumaça aprovados;
- 363 arquivos PHP com sintaxe válida;
- 4 arquivos JavaScript com sintaxe válida;
- manifesto com 103 migrations validado;
- 2.051 instruções SQL reconhecidas pelo parser;
- 1 rollback isolado preservado.

## Publicação

Depois de substituir os arquivos e reiniciar o serviço, execute:

```bash
php /var/www/html/bin/operations-monitor.php
```

Isso grava a nova evidência e encerra automaticamente o alerta antigo quando houver confirmação posterior às falhas.
