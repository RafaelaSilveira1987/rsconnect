# RS Connect 36.6.14 — Planos comerciais claros

## Objetivo

Corrigir a apresentação da tela **Planos e cobranças → Planos**, que na 36.6.13 ainda mantinha visual muito próximo ao formato anterior apesar da nova arquitetura de Canais WhatsApp e Agentes.

## O que muda

- apresentação dos planos por posicionamento comercial;
- `Starter` é apresentado comercialmente como **Essencial**, mantendo a chave interna existente;
- Profissional recebe destaque de **Mais indicado**;
- Business passa a comunicar operação em escala;
- Custom com preço zero aparece como **Sob consulta**;
- separação visual de Canais WhatsApp, Agentes de IA, Usuários e Franquia IA RS;
- recursos do plano são apresentados como capacidades práticas;
- fluxos n8n são descritos comercialmente como **automações integradas**;
- explicação, na própria tela, do que significa canal, agente, franquia e automação;
- formulário de edição de plano passa a explicar cada limite;
- IA com credencial própria é explicitamente descrita como monitorada, mas sem reduzir a franquia RS.

## Banco de dados

Não há migration nesta versão.

## Pós-deploy

Faça recarregamento completo do navegador (`Ctrl+F5`) caso a aba de Planos esteja aberta durante o deploy. O pacote usa `app.css?v=36.6.14` para invalidar o cache do CSS.
