# RS Connect v36.14.0 — Painel executivo de relatórios da RS Admin

## Objetivo

Entregar ao Super Admin uma visão executiva da operação inteira, com leitura rápida, filtros por período e empresa, gráficos responsivos e atalhos para análises detalhadas.

## Instalação

1. Aplique o pacote completo sobre a v36.13.0 em uma branch nova.
2. Preserve o `.env` real fora do Git.
3. Não execute migration nova. A última continua sendo `074_conversation_message_attachments.sql`.
4. Faça o deploy/rebuild no EasyPanel.
5. Atualize o navegador com `Ctrl + F5`.

## Homologação

Acesse `Relatórios` como Super Admin e confirme:

- oito cards executivos carregando;
- filtro por período e por empresa;
- gráfico de atendimentos ao longo do tempo;
- distribuição das interações;
- barras por horário;
- desempenho da equipe;
- resultado da agenda;
- participação da IA;
- exportações rápidas;
- relatórios detalhados mantidos abaixo da visão geral;
- layout responsivo em tablet e celular.

## Diagnóstico SQL

Execute `database/diagnostics/admin_executive_report_v36.14.0.sql`. O primeiro resultado deve mostrar `estruturas_encontradas = 8`.

## Observação sobre dados

Os indicadores usam as tabelas operacionais já existentes. Quando uma estrutura histórica opcional não estiver disponível, o painel continua carregando os demais blocos e apresenta um aviso controlado.
