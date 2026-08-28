# Validação técnica — RS Connect v36.21.0

## Resultado automatizado

- 97 de 97 testes smoke aprovados.
- Sintaxe PHP validada em `app`, `database`, `public`, `routes`, `bin` e `tests`.
- Sintaxe JavaScript validada em todos os arquivos de `public/assets/js`.
- Manifesto de migrations validado com 97 migrations e 1.976 instruções SQL reconhecidas.
- Migration atual: `090_crm_conversation_automation.sql`.

## Pontos validados nesta entrega

- demonstração local da IA na tela de login, sem uso de tokens;
- abertura, fechamento, reinício e navegação por respostas rápidas;
- evolução visual do card comercial durante a demonstração;
- ativação opcional da automação por empresa;
- modos de sugestão e movimentação automática;
- análise econômica por regras e análise contextual por IA;
- confiança mínima, bloqueio de regressão, notificações e auditoria;
- aprovação e rejeição de sugestões;
- bloqueio por negócio e pausa após movimentação manual;
- integração com mensagens recebidas pela Evolution API;
- compatibilidade com testes históricos do projeto.

## Homologação necessária no servidor

A suíte não substitui o teste com o banco e a Evolution API reais. Após publicar, execute a migration, envie mensagens de homologação e confira o comportamento do funil no modo **Apenas sugerir movimentações** antes de ativar o modo automático.
