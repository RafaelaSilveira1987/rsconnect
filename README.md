# RS Connect — v36.18.6

Esta versão fecha a exclusão segura de conexões WhatsApp e remove a necessidade de alterações manuais no banco para apagar uma instância com vínculos.

## Destaques

- Exclusão assistida com prévia dos vínculos.
- Transferência obrigatória quando há dados operacionais.
- Migração de assistentes, contatos, conversas, campanhas e relatórios agendados.
- Consolidação segura de conversas duplicadas e históricos relacionados.
- Proteção das pendências pós-horário durante a troca de canal.
- Confirmação específica para manter uma instância ativa na Evolution.
- Auditoria completa da remoção e da migração.
- Guia de exclusão assistida incluído em `docs/guias`.

## Atualização

- Não exige migration nova.
- Última migration obrigatória: `079_ai_efficiency_phase2_and_report_cleanup.sql`.
- Diagnóstico opcional: `database/diagnostics/instance_assisted_deletion_v36.18.6.sql`.
- Faça rebuild/redeploy e limpe o cache do navegador.

Consulte `INSTRUCOES-v36.18.6.md` e `docs/guias/guia-exclusao-assistida-instancia.md`.
