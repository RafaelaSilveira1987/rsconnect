# RS Connect — v36.20.8

Esta versão corrige o botão da exclusão assistida que podia permanecer bloqueado em **Verificando a conexão externa...** enquanto o polling de status consultava a Evolution.

## O que foi corrigido

- o endpoint de status em tempo real libera a sessão PHP antes de consultar a Evolution;
- a prévia de exclusão também libera a sessão antes da chamada externa;
- o polling de status pausa enquanto a gaveta de exclusão estiver aberta;
- a prévia usa timeout de 20 segundos e mostra uma mensagem clara em vez de ficar indefinidamente em verificação;
- respostas redirecionadas para login ou fora do formato JSON são identificadas;
- erros `connectionState HTTP 404/400` passam a ser reconhecidos como conexão externa ausente;
- quando a Evolution não possui mais a instância, o fluxo muda para **Transferir dados e excluir cadastro** ou **Excluir cadastro do RS Connect**.

## Banco de dados

Não há migration nova. A última migration obrigatória continua sendo:

```text
database/migrations/085_ai_commercial_attention_queue.sql
```

Consulte `INSTRUCOES-v36.20.8.md`.
