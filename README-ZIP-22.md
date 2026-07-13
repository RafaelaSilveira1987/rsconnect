# RS Connect — ZIP 22

## Checklist Comercial de Implantação

Este pacote adiciona uma área de implantação comercial para o Super Admin RS acompanhar, por empresa, se o cliente está pronto para operar.

## Novidades

- Novo menu **Implantação** para Super Admin.
- Checklist por empresa com percentual de conclusão.
- Status comercial: Pendente, Em configuração, Pronta para teste, Em operação e Com pendências.
- Itens automáticos para WhatsApp, Evolution, IA, credenciais, menus, agenda, pré-agendamento, n8n, cobrança, LGPD, monitoramento e backup.
- Opção de marcar item manualmente como concluído, pendente, dispensado ou atenção.
- Ações rápidas para abrir Instâncias, Agentes, Conversas, n8n, Cobrança, Privacidade e Monitoramento.
- Alias em português: `/implantacao`.

## Atualização

1. Suba os arquivos no GitHub.
2. Faça redeploy no EasyPanel.
3. Execute no Adminer:

```text
database/migrations/025_commercial_implementation_checklist.sql
```

4. Acesse:

```text
/implementation
```

ou:

```text
/implantacao
```

5. Clique em **Recalcular todos** para atualizar o status das empresas existentes.

## Observação

Itens opcionais, como n8n e pré-agendamento, podem ser marcados como **Dispensar opcional** quando a empresa não usar aquele recurso.
