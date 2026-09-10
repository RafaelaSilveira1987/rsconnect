# RS Connect 36.30.6 — Correção do alerta de identidade

## Motivo do hotfix

Uma instância podia mostrar simultaneamente **Número verificado** e a mensagem vermelha de que os envios estavam bloqueados. Quando os números autorizado e conectado já eram iguais, a mensagem era indevida.

## Correções

1. `.message-error[hidden]` e o aviso de identidade agora usam `display: none !important`, garantindo que o atributo `hidden` não seja sobrescrito pelo CSS global.
2. A view calcula o estado visual a partir dos números atuais com `EvolutionInstanceSafetyService::assess()`.
3. O polling também oculta um `mismatch` antigo quando os números válidos atuais são exatamente iguais.
4. Um `identity_status=mismatch` persistido anteriormente não bloqueia entrada/saída quando a avaliação atual já é `verified`; mismatch atual ou ainda não reconciliado continua protegido.
5. Conexões saudáveis não exibem `connection_reason` residual, eliminando detalhes como `· 200`.

## Migration

Nenhuma migration nova. Permanece obrigatória:

`109_evolution_instance_identity_cleanup.sql`

Depois do deploy, basta atualizar os arquivos e forçar a atualização do navegador. Não é necessário desconectar o WhatsApp novamente.
