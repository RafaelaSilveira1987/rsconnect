# Homologação final — RS Connect v36.20.7

## Validações executadas

- sintaxe PHP do controller, view, layout e serviço de versão;
- sintaxe JavaScript do arquivo principal;
- regressão da exclusão assistida v36.18.6;
- regressão da conexão externa ausente v36.20.6;
- novo teste do modo de exclusão local v36.20.7.

## Cenários cobertos

### Externa ausente sem vínculos

- opção de exclusão externa oculta;
- etapa de destino oculta;
- botão **Excluir cadastro do RS Connect**;
- auditoria com modo `local_only`.

### Externa ausente com vínculos

- conexão substituta obrigatória;
- botão **Transferir dados e excluir cadastro**;
- auditoria com modo `local_transfer`.

### Externa existente

- fluxo original preservado;
- confirmação adicional mantida quando a conexão continua ativa fora do RS Connect;
- exclusão externa opcional conforme o modo de gerenciamento.

## Resultado

Testes direcionados aprovados e pacote liberado sem migration adicional.
