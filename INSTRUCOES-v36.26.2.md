# RS Connect v36.26.2 — recuperação do monitor financeiro

## Problema corrigido

O monitor considerava qualquer evento financeiro com status de erro ocorrido nos últimos sete dias como falha atual. Por isso, mesmo depois de uma confirmação bem-sucedida e da resolução manual, o aviso retornava na verificação seguinte.

## Nova regra

A verificação agora é feita separadamente para cada gateway ativo:

- última falha posterior à última confirmação: mantém o alerta;
- última confirmação posterior à última falha: considera a integração recuperada;
- falhas de gateways inativos não afetam a saúde atual;
- falhas antigas continuam disponíveis no histórico, mas não reabrem incidentes;
- ao marcar o incidente financeiro como resolvido, o sistema refaz imediatamente a verificação.

## Publicação

Substitua os arquivos e reinicie o serviço. Não existe migration nova.

Depois execute uma rodada do monitor para atualizar a evidência atual:

```bash
php /var/www/html/bin/operations-monitor.php
```

Resultado esperado quando houve sucesso depois das falhas:

```text
Gateways e pagamentos: OK
Falhas históricas recuperadas. Nenhuma falha ativa.
```
