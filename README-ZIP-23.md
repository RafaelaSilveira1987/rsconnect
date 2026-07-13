# RS Connect — ZIP 23

## Onboarding guiado do cliente

Este pacote transforma a configuração inicial do cliente em um fluxo guiado de implantação operacional.

## O que foi incluído

- Tela do cliente **Primeiros passos** (`/onboarding` e `/primeiros-passos`).
- Progresso percentual da conta.
- Etapas centrais:
  1. Dados da empresa.
  2. Conectar WhatsApp.
  3. Agente IA.
  4. Atendimento.
  5. Agenda/pré-agendamento.
  6. LGPD e termos.
  7. Teste final.
- Bloqueios inteligentes entre etapas.
- Histórico de atividades do onboarding.
- Ajuste manual por etapa: automático, pendente, concluído, dispensado ou atenção.
- Configuração rápida de horário de atendimento, mensagem fora de horário e passagem para humano.
- Configuração rápida de agenda/pré-agendamento com aprovação humana.
- Sincronização complementar com o checklist comercial do ZIP 22.
- Sem módulo de campanhas/disparos.

## Como atualizar a partir do ZIP 22 + hotfixes

1. Suba os arquivos no GitHub.
2. Faça redeploy no EasyPanel.
3. No Adminer, execute as migrations na ordem:

```sql
-- se ainda não executou o Hotfix 22.3
source database/migrations/026_fix_implementation_manual_checklist_table.sql;

-- ZIP 23
source database/migrations/027_guided_client_onboarding.sql;
```

Se o Adminer não aceitar `source`, abra o arquivo SQL, copie o conteúdo e execute manualmente.

4. Pressione `Ctrl + F5` no navegador.
5. Entre com uma conta de cliente e acesse:

```text
/onboarding
```

ou:

```text
/primeiros-passos
```

## Observações

- Super Admin continua usando **Implantação** para acompanhar clientes.
- Cliente usa **Primeiros passos** para concluir a própria configuração.
- O onboarding não substitui o monitoramento nem a implantação comercial; ele complementa os dois.
- A rotina de backup via n8n fica para etapa posterior.
