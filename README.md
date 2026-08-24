# RS Connect - v36.18.1

Esta versão corrige a criação de instâncias Evolution (erro PDO HY093 causado por placeholders repetidos) e moderniza o cabeçalho global com busca rápida de módulos, atalhos de ajuda/alertas e identificação do usuário.

## Atualização

- Não exige nova migration.
- Preserve o `.env`.
- Faça rebuild/redeploy e `Ctrl + F5`.
- Teste a criação de uma nova instância em **Canais WhatsApp**.

As migrations anteriores até `079_ai_efficiency_phase2_and_report_cleanup.sql` continuam obrigatórias conforme a origem da instalação.
