
## 34.5.4 — Monitoramento sincronizado

- O botão **Verificar agora** recarrega automaticamente a Central de operação com os cards atualizados.
- O processamento manual da régua de cobrança atualiza o card **Cron de cobrança** antes do redirecionamento.
- O heartbeat interno da régua não aparece mais como serviço duplicado.
- Corrigido parâmetro duplicado no registro das verificações.

## ZIP 33.1 — Vigência e menus do cliente

- Edição direta da vigência de assinaturas, inclusive para empresas bloqueadas.
- Recalculo imediato do acesso após atualização comercial.
- Motivo restante exibido quando uma fatura ainda mantém o bloqueio.
- Atalhos e área exclusiva do Admin RS para mostrar/ocultar módulos do cliente.
- Configurações de módulos preservadas ao cliente salvar o perfil empresarial.

## HOTFIX 32.1.3 — Dashboard: vínculo de dados corrigido

- Corrige colisão da chave `data` com o parâmetro interno de `View::render()`.
- Dashboard administrativo passa a receber o retorno real do serviço.
- Atualiza identificação para `32.1.3-view-binding`.

# HOTFIX 32.1.1 — Dashboard Admin com dados reais

- Removida dependência de `information_schema` nos indicadores do Admin.
- Consultas dos cards executadas diretamente nas tabelas de origem.
- Adicionados horário da atualização e botão para atualizar dados.
- Reforçado o layout da gaveta Cadastrar empresa em zoom 100%.

# ZIP 32.1 — Dashboard Admin confiável + acompanhamento de empresas

- indicadores administrativos consultados diretamente nas tabelas de origem;
- aviso visível quando uma consulta não puder ser atualizada, evitando zeros presumidos;
- acompanhamento manual: atenção, visualizada, corrigida e análise automática;
- falhas antigas de IA e integrações podem ser reconhecidas após correção;
- botões diretos para inativar e reativar empresas;
- histórico focado em correções e atualizações administrativas;
- últimas empresas cadastradas com data de cadastro;
- gaveta de cadastro corrigida para zoom 100% e mobile;
- migration 035 para acompanhamento administrativo.

# ZIP 32.0 — Admin RS: Dashboard executivo + Empresas

- novo dashboard executivo exclusivo do Super Admin RS;
- indicadores de empresas, implantação, assinaturas, receita, mensagens e incidentes;
- saúde dos serviços e clientes priorizados por necessidade de atenção;
- atalhos operacionais e histórico administrativo recente;
- listagem de empresas com filtros e classificação de saúde;
- cadastro de empresa em gaveta lateral responsiva;
- nova visão geral por empresa com plano, uso, implantação, equipe, falhas e acessos rápidos;
- telas do cliente preservadas;
- sem migration nova.

# ZIP 31.3 — UX do cliente: Privacidade, Assinatura e Acessos

- reformulação da tela Privacidade e dados com linguagem simples e configuração em etapas;
- nova experiência para solicitações LGPD, aceites e exportação de dados;
- reformulação de Minha assinatura com uso do plano e cobranças responsivas;
- botão de WhatsApp para solicitar melhoria do plano à equipe RS Connect;
- reformulação de Permissões para Acessos da equipe no lado do cliente;
- perfis e acessos apresentados sem chaves técnicas;
- telas do Super Admin RS preservadas;
- sem migration nova.

# ZIP 31.2 — Central de ajuda como manual dos módulos

- Central do cliente reduzida aos manuais dos módulos existentes.
- Cada módulo abre resumo, funções, passo a passo e orientações em uma gaveta lateral.
- Botão interno para acessar diretamente o módulo.
- Ordem recomendada e diagnóstico rápido mantidos somente para Super Admin RS.
- Layout responsivo para desktop, tablet e celular.
- Sem migration nova.

# ZIP 31.1 — Notificações configuráveis e sininho em tempo real

- Preferências por empresa para mensagens, IA, automações, agenda, financeiro e avisos importantes.
- Notificação de nova mensagem recebida.
- Alertas amigáveis para falhas da IA e ausência de assistente ativo.
- Alertas para falhas de n8n, webhooks e integrações externas.
- Notificações de pré-agendamentos e mudanças da agenda.
- Contador do sininho atualizado automaticamente sem recarregar a página.
- Toast discreto ao chegar novo aviso.
- Migration 034 para persistir preferências da empresa.

# ZIP 31.0 — UX cliente: Assistentes + Perfil da empresa

- reformulação da gaveta de criação de assistentes;
- cadastro guiado e linguagem voltada para usuários leigos;
- prompt inicial montado a partir de objetivo, tom, mensagem e regras;
- informações empresariais reaproveitadas na criação do assistente;
- reformulação da tela de dados da empresa no lado do cliente;
- novos campos comerciais, endereço e contexto para atendimento;
- barra fixa para salvar e indicador de preenchimento;
- responsividade para desktop, tablet e celular;
- preservação do layout atual do Super Admin RS;
- migration 033 para os novos campos do perfil empresarial.

## HOTFIX 31.0.1
- Corrigido o painel de criação de assistente em zoom 100%.
- Impedido que regras antigas de `.conversation-details` transformem a gaveta em grid de três colunas.
- Atualizado cache-busting dos assets para `31.0.1`.

## ZIP 32.2 — Credenciais de IA

- Nova tela administrativa de credenciais de IA.
- Cadastro e edição por gaveta lateral responsiva.
- Remoção do formulário fixo lateral e do formulário embutido na tabela.
- Cards, indicadores e filtros para facilitar a gestão.
- Linguagem simplificada e melhor orientação sobre chave, modelo e escopo.


## ZIP 32.3 — Centros administrativos
- WhatsApp, n8n, cobrança, gateways, régua, usuários e permissões redesenhados com cards, filtros e gavetas responsivas.
- Formulários laterais permanentes removidos do Admin RS.
- Experiência do cliente preservada.

## ZIP 33.0 — Segurança comercial e controle de acesso

- bloqueio por fim de vigência da assinatura;
- bloqueio por fatura vencida há mais de cinco dias;
- bloqueio temporário por tentativas incorretas de login;
- desbloqueio automático após regularização;
- painel Segurança validado com dados reais;
- sessões com expiração e encerramento registrados;
- mensagens preservadas durante bloqueio, sem IA e automações;
- tela específica de acesso temporariamente limitado.

## ZIP 34.0 — 2026-07-17

- CRM comercial exclusivo do Admin RS.
- Funil de prospecção, demonstração, proposta, negociação, implantação, cliente ativo, risco e cancelamento.
- Atividades, notas, responsáveis, valor e conversão em empresa.
- Relatórios executivos de crescimento, receita, uso, IA, n8n, agenda e comercial.
- Exportações administrativas em CSV.
- Migration `037_admin_commercial_crm_reports.sql`.

## ZIP 34.1

- Corrige a apresentação dos relatórios executivos com CSS e JavaScript isolados.
- Adiciona fallback visual para impedir relatório sem formatação.
- Implementa arrastar e soltar no CRM administrativo e no CRM do cliente.
- Salva a mudança de etapa por AJAX, sem refresh.
- Mantém seletor de etapa como alternativa acessível/mobile, também sem refresh.
- Cria relatório gerencial do cliente com métricas de atendimento, IA, equipe, CRM e agenda.
- Atualiza a versão dos assets para 34.1.

## ZIP 34.2
- Relatórios administrativos e gerenciais sem abas: todos os conteúdos aparecem em cards na mesma página.
- Índice visual com atalhos para Crescimento, Receita, Uso, IA, Agenda e Comercial.
- CRM mantém arrastar e soltar sem refresh, sem a faixa informativa permanente.

## ZIP 34.3.1

- Mantém o intervalo mínimo entre respostas configurável por assistente.
- Mensagens recebidas durante o intervalo permanecem salvas e registradas como pendentes.
- Salvar configurações ou instruções reavalia automaticamente a última pendência de cooldown.
- Reprocessamento manual ignora somente a trava de intervalo, preservando as demais regras.
- Proteção contra resposta duplicada antes do reprocessamento.
- Reações do WhatsApp são ignoradas por padrão e podem ser habilitadas por assistente.
- Migration 038 não altera valores existentes de `cooldown_seconds`.


## ZIP 34.4 — Saúde do cliente e diagnóstico por empresa

- nova página de saúde por empresa, exclusiva do Super Admin;
- snapshots de WhatsApp, IA, n8n, agenda, assinatura e segurança;
- consulta da conexão e webhook diretamente na Evolution;
- incidentes deduplicados com visualização, acompanhamento e resolução;
- resolução automática quando a falha deixa de existir;
- histórico completo de incidentes;
- integração com Dashboard, Empresas e Checklist de implantação;
- execução manual, por CLI ou webhook protegido;
- migration `039_tenant_health_diagnostics.sql`.

## ZIP 34.4.1 — Configurações completas por empresa

- botão **Ver todas as configurações** na saúde do cliente;
- inventário central de empresa, assinatura, WhatsApp, IA, n8n, agenda, usuários, menus, notificações e privacidade;
- prompts e bases de conhecimento consultáveis em painéis recolhíveis;
- chaves, tokens e senhas sempre protegidos;
- busca, índice, expansão em massa e cópia de resumo técnico;
- gaveta responsiva, sem rolagem horizontal.

## ZIP 34.5 — Fluxo seguro de atendimento e administração de assistentes

- Corrige a tela de Assistentes de IA do Super Admin, com seleção da empresa e carregamento das configurações corretas.
- Links da Saúde e diagnóstico passam a abrir `/agents` já filtrado pela empresa.
- Adiciona grupo de atendimento aos contatos: não identificado, interessado, paciente atual, familiar, casal e outro.
- Salva a etapa atual da conversa, a situação da demanda e seu resumo.
- Envia grupo, status, tags, etapa e demanda para o contexto da IA.
- Bloqueia a criação de novo pré-agendamento enquanto a demanda não estiver coletada, recusada ou dispensada.
- Permite remarcação de paciente atual sem repetir a queixa quando a regra do grupo autorizar.
- Adiciona regras configuráveis por grupo em cada assistente.
- Permite revisar manualmente grupo, etapa e demanda na gaveta da conversa.
- Migration `040_conversation_flow_contact_groups.sql`.

## 34.5.1 — Horário local e pendências reais da IA

- Converte datas do diagnóstico do fuso da sessão MySQL para `APP_TIMEZONE`.
- Substitui a contagem de logs `ai.cooldown` pela contagem real de conversas sem resposta posterior.
- Separa conversas pendentes de mensagens acumuladas.
- Altera o status do assistente para Atenção quando há conversa aguardando resposta.
- Adiciona o botão Reprocessar agora na Saúde do cliente.
- Inclui diagnóstico SQL de timezone e pendências.

## ZIP 34.5.2

- adiciona ocorrências reais de IA e integrações à Saúde e diagnóstico;
- alinha a tela de diagnóstico aos badges “ainda não revisadas” da listagem de empresas;
- inclui filtros, detalhes técnicos e links para correção;
- permite revisar o lote atual ou marcar a empresa como corrigida;
- torna os badges de falha da listagem de empresas clicáveis;
- após Verificar agora, direciona para a seção de ocorrências;
- atualiza cache visual para 34.5.2.
## ZIP 34.5.3

- Corrigido parâmetro ausente `:seen_at` no sincronismo de incidentes da saúde do cliente.
- Monitoramento reconhece credenciais de IA por empresa e heartbeat real da régua de cobrança.
- Ações operacionais adicionadas aos avisos de backup, cobrança, IA, n8n e Evolution.
- Status técnicos traduzidos para linguagem do usuário.

