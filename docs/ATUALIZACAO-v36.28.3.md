# RS Connect 36.28.3 — Configuração de assistentes responsiva

## Objetivo

Corrigir a tela de configuração de agentes quando existem vários assistentes lado a lado.

## Problema corrigido

Ao abrir **Configurações completas** dentro de um card estreito, o formulário de canais permanecia preso à largura da coluna. Isso espremia os campos e podia fazer opções de WhatsApp, roteamento e intenções ultrapassarem visualmente o card.

## Novo comportamento

- ao abrir **Configurar assistente**, o card selecionado passa a ocupar toda a largura da grade;
- somente um painel de configuração completo fica aberto por vez;
- os cards de WhatsApp se reorganizam automaticamente conforme a largura disponível;
- campos, selects e áreas de texto ficam limitados ao próprio painel;
- em tablets e celulares, configurações de duas colunas viram uma coluna;
- ao fechar as configurações, o assistente volta ao card compacto;
- textos de roteamento foram simplificados para linguagem de operação.

## Banco de dados

Não há migration nova nesta versão. A migration obrigatória continua sendo `104_customer_patient_continuity_guard.sql`.

## Deploy

Depois de substituir os arquivos, reinicie o PHP-FPM/container para invalidar OPcache e faça recarga forçada do navegador para atualizar CSS e JavaScript.
