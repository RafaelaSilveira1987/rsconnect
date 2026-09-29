# Atualização RS Connect 36.39.1

Esta versão corrige a leitura dos horários explicitamente publicados na Agenda interna.

## O que muda

- consultas amplas não herdam horário exato de tentativa anterior;
- preferências ainda não confirmadas não bloqueiam disponibilidade do contato;
- vagas publicadas não são removidas por regras genéricas de dia depois de já terem sido liberadas;
- pré-agendamentos de IA podem descobrir vagas de profissionais diferentes antes da seleção;
- publicar uma vaga ativa o modo de disponibilidade publicada quando a origem da empresa é a Agenda interna.

Não há migration nova. Permanece `122_calendar_client_communications.sql`.
