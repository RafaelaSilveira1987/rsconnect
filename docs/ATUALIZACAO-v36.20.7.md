# Atualização v36.20.7 — exclusão local assistida

## Problema corrigido

Quando a conexão já não existia na Evolution, o modal identificava corretamente a ausência externa, mas ainda mantinha textos genéricos como **Transferir e remover** e **Revisei os vínculos e o destino informado**. Isso deixava dúvida sobre o que seria realmente apagado.

## Solução aplicada

O fluxo agora possui três estados claros:

1. **Exclusão assistida:** a conexão ainda existe externamente.
2. **Exclusão local com transferência:** a conexão externa não existe, mas há dados vinculados.
3. **Exclusão somente local:** a conexão externa não existe e não há vínculos operacionais para transferir.

## Regras preservadas

- dados vinculados nunca são descartados silenciosamente;
- havendo vínculos, a conexão substituta continua obrigatória;
- a Evolution não é chamada quando a conexão já não existe;
- a confirmação digitada continua obrigatória;
- a situação final é registrada na auditoria.

## Banco de dados

Nenhuma migration nova é necessária.
