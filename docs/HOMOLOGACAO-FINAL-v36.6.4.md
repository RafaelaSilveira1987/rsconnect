# RS Connect 36.6.4 — Homologação do Painel operacional

## Objetivo
Corrigir o carregamento real dos dados na nova visão paralela de saúde, mantendo a Central de operação antiga intacta.

## Causa identificada
`View::render()` recebia seus parâmetros em uma variável local chamada `$data` e usava `extract(..., EXTR_SKIP)`. Quando um controller enviava a chave `data`, o PHP não a extraía porque `$data` já existia no escopo do renderer. A view recebia o envelope de parâmetros, não o payload do serviço.

## Resultado esperado após o deploy
- `Disponíveis` mostra o total realmente comprovado pelos checks recentes.
- `Sem evidência` mostra serviços ausentes/antigos, em vez de zero artificial.
- `Problemas ativos` recebe bloqueios externos e falhas reais existentes.
- `Saúde dos serviços`, `Rotinas automáticas`, `Saúde por empresa` e histórico usam os dados reais do serviço.
- Mariana deve aparecer como bloqueio externo enquanto a Evolution estiver indisponível e existirem mensagens preservadas.
- O botão **Verificar sistema agora** continua renovando as evidências e retorna ao Painel operacional.

## Sem migration
Esta versão altera somente transporte/apresentação de dados.
