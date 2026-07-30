<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Env;
use PDO;
use Throwable;

final class CommercialBetaService
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::connection();
    }

    public function dashboard(): array
    {
        $checks = $this->checks();
        $ok = count(array_filter($checks, static fn (array $check): bool => ($check['status'] ?? '') === 'ok'));
        $warning = count(array_filter($checks, static fn (array $check): bool => ($check['status'] ?? '') === 'warning'));
        $blocked = count(array_filter($checks, static fn (array $check): bool => ($check['status'] ?? '') === 'blocked'));
        $score = count($checks) > 0 ? (int) round(($ok / count($checks)) * 100) : 0;

        return [
            'score' => $score,
            'ok' => $ok,
            'warning' => $warning,
            'blocked' => $blocked,
            'status_label' => $this->statusLabel($score, $blocked),
            'checks' => $checks,
            'metrics' => $this->metrics(),
            'quick_actions' => $this->quickActions(),
            'release_notes' => $this->releaseNotes(),
            'version_label' => 'Beta Comercial 1.0',
            'operational_routine' => $this->operationalRoutine(),
        ];
    }

    private function checks(): array
    {
        $checks = [];

        $activeTenants = $this->countWhere('tenants', "status = 'active'");
        $checks[] = $this->check(
            'Base de clientes',
            $activeTenants > 0 ? 'ok' : 'blocked',
            $activeTenants . ' empresa(s) ativa(s) cadastrada(s).',
            'Cadastrar pelo menos uma empresa ativa para operar o SaaS.'
        );

        $avgImplementation = $this->number('SELECT ROUND(AVG(percent_complete)) FROM tenant_implementation_status');
        $checks[] = $this->check(
            'Implantação comercial',
            $avgImplementation >= 70 ? 'ok' : ($avgImplementation > 0 ? 'warning' : 'blocked'),
            'Média de implantação: ' . $avgImplementation . '%.',
            'Use o módulo Implantação para concluir pendências por empresa.'
        );

        $connectedInstances = $this->countWhere('evolution_instances', "status IN ('connected','open','active','online')");
        $checks[] = $this->check(
            'WhatsApp/Evolution',
            $connectedInstances > 0 ? 'ok' : 'warning',
            $connectedInstances . ' instância(s) com status conectado/ativo.',
            'Conectar ao menos uma instância e validar envio/recebimento.'
        );

        $aiConfigured = $this->countWhere('ai_provider_credentials', "status = 'active'") > 0 || (string) Env::get('OPENAI_API_KEY', '') !== '';
        $checks[] = $this->check(
            'IA configurada',
            $aiConfigured ? 'ok' : 'warning',
            $aiConfigured ? 'Credencial de IA encontrada.' : 'Nenhuma credencial ativa de IA detectada.',
            'Configure a credencial de IA global ou por empresa antes de vender automação.'
        );

        $messages24h = $this->number("SELECT COUNT(*) FROM conversation_messages WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        $checks[] = $this->check(
            'Conversas e webhooks',
            $messages24h > 0 ? 'ok' : 'warning',
            $messages24h . ' mensagem(ns) registrada(s) nas últimas 24h.',
            'Enviar uma mensagem de teste por uma instância conectada.'
        );

        $backupOk = $this->number("SELECT COUNT(*) FROM system_backups WHERE backup_type = 'automatic' AND status = 'success' AND created_at >= DATE_SUB(NOW(), INTERVAL 72 HOUR)");
        $routineOk = $this->number("SELECT COUNT(*) FROM operations_backup_routines WHERE status = 'active' AND last_success_at IS NOT NULL");
        $checks[] = $this->check(
            'Backup automático',
            ($backupOk > 0 && $routineOk > 0) ? 'ok' : 'warning',
            $backupOk . ' backup(s) automático(s) OK em 72h; ' . $routineOk . ' rotina(s) validada(s).',
            'Manter rotina ativa, n8n gerando dump real e callback validado.'
        );

        $healthDown = $this->latestHealthCount(['down']);
        $healthWarning = $this->latestHealthCount(['warning']);
        $checks[] = $this->check(
            'Monitoramento',
            $healthDown === 0 ? ($healthWarning === 0 ? 'ok' : 'warning') : 'blocked',
            $healthWarning . ' aviso(s) e ' . $healthDown . ' falha(s) no último status por serviço.',
            'Abrir Monitoramento e resolver serviços em aviso ou falha.'
        );

        $privacyRows = $this->countTable('tenant_privacy_settings') + $this->countTable('privacy_settings');
        $checks[] = $this->check(
            'LGPD e aceite',
            $privacyRows > 0 ? 'ok' : 'warning',
            $privacyRows > 0 ? 'Configuração LGPD encontrada.' : 'Configuração LGPD não localizada.',
            'Revisar termos, política e aceite obrigatório por empresa.'
        );

        $plans = $this->countTable('saas_plans') + $this->countTable('subscription_plans');
        $gateways = $this->countTable('payment_gateways');
        $checks[] = $this->check(
            'Cobrança SaaS',
            ($plans > 0 || $gateways > 0) ? 'ok' : 'warning',
            $plans . ' plano(s) e ' . $gateways . ' gateway(s) detectado(s).',
            'Manter ao menos um plano e um processo de cobrança definido.'
        );

        $n8nFlows = $this->countWhere('n8n_tenant_flows', "status = 'active'") + $this->countWhere('n8n_flows', "status = 'active'");
        $checks[] = $this->check(
            'n8n por empresa',
            $n8nFlows > 0 ? 'ok' : 'warning',
            $n8nFlows . ' fluxo(s) ativo(s) detectado(s).',
            'Usar fluxos n8n por empresa quando houver agenda, cobrança ou backup.'
        );

        return $checks;
    }

    private function metrics(): array
    {
        return [
            'tenants' => $this->countTable('tenants'),
            'active_tenants' => $this->countWhere('tenants', "status = 'active'"),
            'implementation_avg' => $this->number('SELECT ROUND(AVG(percent_complete)) FROM tenant_implementation_status'),
            'implementation_testing' => $this->countWhere('tenant_implementation_status', "status IN ('testing','operating','ready')"),
            'conversations_24h' => $this->number("SELECT COUNT(*) FROM conversation_messages WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"),
            'automatic_backups' => $this->number("SELECT COUNT(*) FROM system_backups WHERE backup_type = 'automatic' AND status = 'success'"),
            'backup_routines' => $this->countWhere('operations_backup_routines', "status = 'active'"),
            'last_backup' => $this->singleValue("SELECT created_at FROM system_backups WHERE status = 'success' ORDER BY created_at DESC LIMIT 1"),
        ];
    }

    private function quickActions(): array
    {
        return [
            ['label' => 'Ver implantação', 'url' => '/implantacao', 'scope' => 'super_admin'],
            ['label' => 'Abrir monitoramento', 'url' => '/monitoramento', 'scope' => 'super_admin'],
            ['label' => 'Backup automático', 'url' => '/backup-automatico', 'scope' => 'super_admin'],
            ['label' => 'n8n', 'url' => '/n8n', 'scope' => 'super_admin'],
            ['label' => 'Primeiros passos', 'url' => '/primeiros-passos', 'scope' => 'client'],
            ['label' => 'Privacidade/LGPD', 'url' => '/privacy', 'scope' => 'all'],
        ];
    }

    private function releaseNotes(): array
    {
        return [
            ['version' => '36.10.3', 'title' => 'Status e ciclos sincronizados', 'summary' => 'Fecha e reabre o ciclo operacional junto com o status real da conversa, adiciona garantia no backend e repara divergências existentes pela migration 070.'],
            ['version' => '36.10.2', 'title' => 'Status visual das conversas', 'summary' => 'Diferencia conversas abertas, pendentes e encerradas por cor na lista e no painel selecionado, com atualização em tempo real, mantendo junto a recuperação resiliente dos ciclos da migration 069.'],
            ['version' => '36.10.1', 'title' => 'Ciclos de atendimento resilientes', 'summary' => 'Recupera conversas sem ciclo ativo, preenche primeira entrada e primeira resposta com mensagens reais e torna o trigger de mensagens autocorretivo para evitar lacunas futuras nos relatórios.'],
            ['version' => '36.10.0', 'title' => 'Relatórios de equipe e profissionais', 'summary' => 'Adiciona visão consolidada e individual por profissional com conversas respondidas, primeira resposta por ciclo persistente, transferências, clientes preferenciais, agenda, comparecimento, histórico operacional e exportação CSV com escopo próprio ou da equipe.'],
            ['version' => '36.9.1', 'title' => 'Base histórica e métricas por profissional', 'summary' => 'Registra atribuições, transferências, ciclos de conversa, primeira resposta humana, mudanças de responsável e status da agenda, preparando relatórios confiáveis com permissões próprias e da equipe.'],
            ['version' => '36.9.0', 'title' => 'Identificadores públicos UUID', 'summary' => 'Substitui IDs numéricos nas URLs por UUIDs públicos criptografados e autenticados, mantém compatibilidade com links antigos e preserva as chaves numéricas somente no backend e no banco.'],
            ['version' => '36.8.2', 'title' => 'Calendário da agenda', 'summary' => 'Mantém a lista operacional e adiciona visualizações Dia, Semana e Mês, filtros por profissional e status, navegação entre períodos e detalhes do compromisso em modal. A última visualização fica salva por usuário no navegador.'],
            ['version' => '36.8.1', 'title' => 'Conflito do cliente na agenda', 'summary' => 'Impede o mesmo contato de ocupar horários sobrepostos, mesmo com profissionais diferentes. A regra é configurável por empresa e fica ativa por padrão na agenda por profissional.'],
            ['version' => '36.8.0', 'title' => 'Agenda opcional por profissional', 'summary' => 'Adiciona horários e calendário individual por usuário, seleção manual do profissional e bloqueio de conflitos apenas na agenda escolhida. O reaproveitamento automático do responsável da conversa permanece opcional e desligado por padrão.'],
            ['version' => '36.7.0', 'title' => 'Atendimento opcional por profissional', 'summary' => 'Permite vincular um profissional preferido ao contato, assumir e transferir conversas e bloquear interferência enquanto o atendimento estiver ativo. A atribuição automática permanece opcional e desligada por padrão.'],
            ['version' => '36.6.39', 'title' => 'Assinatura humana no WhatsApp', 'summary' => 'Entrega o nome e a função do atendente ao cliente também quando a mensagem é enviada por um Super Admin global, sem assinar respostas da IA.'],
            ['version' => '36.6.38', 'title' => 'Status Evolution confiável', 'summary' => 'Corrige parâmetros PDO repetidos que impediam o polling e o webhook de salvar o estado real da conexão, registra falhas e usa endpoint compatível com a URL base da aplicação.'],
            ['version' => '36.6.36', 'title' => 'Governança de mensagens e Evolution em tempo real', 'summary' => 'Identifica o atendente nas respostas humanas, aplica retenção configurável e acompanha QR/conexão da Evolution por webhook com reconciliação automática.'],
            ['version' => '36.6.35', 'title' => 'Prompt Studio', 'summary' => 'Cria prompts guiados, valida conflitos operacionais e preserva versões restauráveis por assistente.'],
            ['version' => '36.6.34.2', 'title' => 'Agenda interna no onboarding', 'summary' => 'Torna explícita a escolha entre Agenda interna, Agenda inteligente homologada pela RS e operação sem agenda, sem expor n8n ou Google Calendar ao cliente.'],
            ['version' => '36.6.34.1', 'title' => 'Hotfix de login e sessão', 'summary' => 'Corrige o redirecionamento de CSRF expirado no login, impede duplicação de APP_URL e recupera URLs antigas malformadas com segurança.'],
            ['version' => '36.6.34', 'title' => 'Teste gratuito e primeiro acesso guiado', 'summary' => 'Transforma o fim do teste em regra efetiva de acesso e cobrança e conduz a implantação em sequência: cadastro, LGPD, operação, agenda, WhatsApp, agente e teste final.'],
            ['version' => '36.6.33', 'title' => 'Busca funcional na Base de contatos', 'summary' => 'Corrige parâmetros repetidos da consulta com PDO nativo, normaliza telefone, pesquisa nome/e-mail/empresa/tags e aplica o filtro automaticamente durante a digitação.'],
            ['version' => '36.6.32', 'title' => 'Recuperação pós-horário da Agenda', 'summary' => 'Retoma pedidos de agenda pela máquina determinística antes de chamar a IA, reúne mensagens fragmentadas e bloqueia ai.replied no writer do Google Calendar mesmo sem cadastro de fluxo.'],
            ['version' => '36.6.31', 'title' => 'Novo atendimento em drawer', 'summary' => 'Move o formulário de nova conversa para um drawer independente da Caixa de Entrada, eliminando recortes em telas estreitas e preservando busca preventiva e posição da lista.' ],
            ['version' => '36.6.30', 'title' => 'Busca inicial, horários por dia e telemetria clara', 'summary' => 'Adiciona busca de contato antes da primeira conversa, permite faixas de atendimento diferentes por dia, refina telas de LGPD/contato/consumo e alinha a franquia de IA somente a respostas efetivamente entregues.'],
            ['version' => '36.6.29', 'title' => 'Busca confiável e avatar do contato', 'summary' => 'Corrige a pesquisa da Caixa de Entrada com busca por nome, telefone normalizado e histórico de mensagens, adiciona busca instantânea e exibe foto de perfil do WhatsApp quando disponível pela Evolution.'],
            ['version' => '36.6.28', 'title' => 'Polimento visual da Central de comunicação', 'summary' => 'Refina a experiência do Admin com formulário em etapas, campos estilizados, destinatários e canais em cards, preview em tempo real e hierarquia visual alinhada ao RS Connect.'],
            ['version' => '36.6.27', 'title' => 'Central de comunicação refinada', 'summary' => 'Garante entrega in-app independente do menu de Notificações, renderiza a caixa no servidor e reorganiza o Admin em novo comunicado, histórico e respostas.'],
            ['version' => '36.6.26', 'title' => 'Agenda resiliente e identidade confiável', 'summary' => 'Recupera retornos de disponibilidade pela fila rápida e valida nomes do WhatsApp antes de promovê-los ao cadastro.'],
            ['version' => '36.6.25', 'title' => 'Central de comunicação in-app', 'summary' => 'Cria caixa de mensagens flutuante para não lidos, drawer de leitura, confirmação/resposta e histórico de conversas entre RS e empresa.'],
            ['version' => '36.6.24', 'title' => 'Modalidade antes da disponibilidade', 'summary' => 'Pergunta Online ou Presencial antes de consultar a agenda, filtra eventos VAGO pela modalidade escolhida e reinicia a busca quando a modalidade muda.'],
            ['version' => '36.6.23', 'title' => 'Google Agenda somente após confirmação real', 'summary' => 'Impede manutenção e ciclos diretos de criarem eventos para registros apenas agendados/pré-agendados e exige confirmação real no contrato do Google.'],
            ['version' => '36.6.22', 'title' => 'Homologação alinhada à migration 056', 'summary' => 'Atualiza o painel técnico para refletir a migration 056 e valida se o writer da Agenda Google está restrito a compromissos reais.'],
            ['version' => '36.6.21', 'title' => 'Horário confiável e Agenda com contrato forte', 'summary' => 'Prefere agente realmente disponível no canal, revalida o expediente antes do envio e impede que integrações legadas transformem respostas comuns em eventos do Google Calendar.'],
            ['version' => '36.6.20', 'title' => 'Agenda por intenção real e callback de backup resiliente', 'summary' => 'Exige pedido explícito para agenda, protege o writer do Google Calendar contra eventos genéricos e torna o callback de backup resiliente a redeploy/rotação de token.'],
            ['version' => '36.6.19', 'title' => 'Retry confiável de backup', 'summary' => 'Alinha o dispatcher ao último sucesso real, permite nova tentativa no mesmo dia após falha/timeout e explica o motivo de cada rotina elegível ou ignorada.'],
            ['version' => '36.6.18', 'title' => 'Manutenção da agenda auditável', 'summary' => 'Separa execução manual da automática via n8n, alinha callbacks vencidos com a regra real da manutenção e registra resultado/origem por empresa.'],
            ['version' => '36.6.17', 'title' => 'Manutenção automática da agenda', 'summary' => 'Adiciona o template n8n ausente para manutenção da agenda, injeta domínio/token no download e autentica a rotina por header seguro.'],
            ['version' => '36.6.16', 'title' => 'Conversas compactas, aviso diário e tempo de interação', 'summary' => 'Mantém lista e histórico com scroll independente, permite novo aviso de ausência em um novo dia e faz IA/agenda aguardarem o tempo configurado após a última mensagem recebida.'],
            ['version' => '36.6.15', 'title' => 'Confiabilidade das regras do agente', 'summary' => 'Centraliza a política de horário, impede agenda e callbacks fora do expediente, reduz falsos gatilhos de agendamento e torna classificação, grupo, tags e contexto efetivo visíveis na conversa.'],
            ['version' => '36.6.14', 'title' => 'Planos comerciais claros', 'summary' => 'Reorganiza a apresentação comercial dos planos, diferencia canais, agentes, usuários, automações e franquia de IA, e apresenta o Custom como sob consulta.'],
            ['version' => '36.6.13', 'title' => 'Canais WhatsApp e roteamento de agentes', 'summary' => 'Centraliza múltiplos números em uma única tela, permite vários agentes por canal e vários canais por agente, com principal, especialistas, palavras de roteamento e fixação por conversa.'],
            ['version' => '36.6.12', 'title' => 'Métricas completas de IA e franquia RS', 'summary' => 'Separa mensagens, interações entregues, chamadas ao provedor, tokens e custo técnico; a franquia continua consumida apenas por respostas entregues com IA custeada pela RS.'],
            ['version' => '36.6.11', 'title' => 'Uso total de IA e menus consistentes', 'summary' => 'Conta também o uso de IA com credencial própria sem consumir franquia RS, reorganiza notificações e faz as marcações de menu do Admin refletirem na navegação do cliente.'],
            ['version' => '36.6.10', 'title' => 'Fila rápida validada e franquia reparada', 'summary' => 'Renomeia o template da fila rápida, reforça o POST/token e corrige planos que apareciam com interações de IA sem limite definido.'],
            ['version' => '36.6.9', 'title' => 'Intervalo real e conversa contínua', 'summary' => 'Faz o intervalo mínimo valer novamente para mensagens novas, adiciona fila rápida de 1 minuto e mantém a conversa no campo de digitação após envio humano.'],
            ['version' => '36.6.8', 'title' => 'Consumo de IA e recuperação pós-horário', 'summary' => 'Conta apenas respostas automáticas de IA custeadas pela RS Connect, separa credencial própria e retoma conversas recebidas fora do expediente com segurança.'],
            ['version' => '36.6.7', 'title' => 'Severidade operacional e diagnóstico da IA', 'summary' => 'Distingue desativação manual de falha técnica, reduz falsos alertas de tokens e trata ausência de eventos financeiros como falta de evidência.'],
            ['version' => '36.6.6', 'title' => 'Takeover humano e continuidade de clientes', 'summary' => 'Bloqueia toda automação quando a equipe assume a conversa e evita nova triagem de clientes/pacientes já identificados.'],
            ['version' => '36.6.5', 'title' => 'Resolução e comunicação operacional', 'summary' => 'Conecta problemas a playbooks de correção, cria alertas do Super Admin com recuperação e adiciona comunicados para clientes.'],
            ['version' => '36.6.4', 'title' => 'Dados operacionais corrigidos', 'summary' => 'Corrige o transporte do payload das views para o Painel operacional e demais telas que recebem o conjunto data.'],
            ['version' => '36.6.3', 'title' => 'Saúde operacional por evidência', 'summary' => 'Cria uma fonte única de saúde com validade da evidência, problemas ativos, matriz de serviços, rotinas e empresas sem falsos verdes.'],
            ['version' => '36.6.2', 'title' => 'Painel operacional paralelo', 'summary' => 'Cria uma nova visão simplificada de operação sem substituir a Central técnica existente.'],
            ['version' => '36.6.1', 'title' => 'Evolution ao vivo e backup n8n', 'summary' => 'Valida a Evolution ao vivo na fila da IA e remove dependência de $env no workflow de backup.'],
            ['version' => '36.6.0', 'title' => 'Estabilização operacional', 'summary' => 'Corrige execução do backup via SSH, sincroniza revisão de incidentes e trata fila bloqueada por Evolution desconectada sem gerar novas falhas.'],
            ['version' => '36.5.9', 'title' => 'Central de operação e diagnóstico da fila', 'summary' => 'Fixa o hamburger no viewport, contextualiza tokens e identifica pendências da IA por instância Evolution.'],
            ['version' => '36.5.8', 'title' => 'Administração RS e monitoramento', 'summary' => 'Reorganiza o menu, agrupa n8n e amplia a Central de operação com evidências, busca e filtros.'],
            ['version' => '36.5.7', 'title' => 'Identificação e cron seguro', 'summary' => 'Corrige nome automático de contatos, melhora toque mobile e endurece a ativação do cron de cobrança.'],
            ['version' => '36.5.6', 'title' => 'Homologação final', 'summary' => 'Corrige contexto de clientes, takeover humano da IA, reprocessamento, cron e responsividade.'],
            ['version' => '36.5.5', 'title' => 'Prontidão beta e Minha empresa', 'summary' => 'Alinha o diagnóstico à migration 048 e reorganiza melhor o bloco de endereço da empresa.'],
            ['version' => '36.5.4', 'title' => 'Equipe e acessos em drawer', 'summary' => 'Cadastro e edição de usuários passam a abrir em gaveta lateral, no padrão de Contatos.'],
            ['version' => '36.5.3', 'title' => 'Cadastro mais limpo e endereço por CEP', 'summary' => 'Dados mestres compactos e preenchimento automático de endereço ao informar o CEP.'],
            ['version' => '36.5.2', 'title' => 'Minha empresa como central administrativa', 'summary' => 'Abas internas, proteção dos dados mestres e reorganização da área administrativa do cliente.'],
            ['version' => '36.5.1', 'title' => 'Comercial em tempo real', 'summary' => 'Indicadores do Kanban atualizados instantaneamente após mover oportunidades.'],
            ['version' => '36.4.7', 'title' => 'Relatórios refinados', 'summary' => 'Melhorias visuais, métricas e consistência dos relatórios do cliente e do Super Admin.'],
            ['version' => '36.3.0', 'title' => 'Backup operacional confiável', 'summary' => 'Rotina de backup com job real, callback idempotente, timeout e histórico operacional.'],
        ];
    }

    private function operationalRoutine(): array
    {
        return [
            'Diário' => ['Monitoramento sem falhas críticas', 'Conversas recebendo mensagens', 'Backup automático concluído ou job n8n sem erro'],
            'Semanal' => ['Revisar implantação das empresas', 'Conferir cobrança/régua', 'Testar uma conversa com IA por cliente ativo'],
            'Antes de novo cliente' => ['Criar empresa e usuário', 'Conectar WhatsApp', 'Criar agente IA', 'Configurar LGPD', 'Concluir checklist de implantação'],
        ];
    }

    private function statusLabel(int $score, int $blocked): string
    {
        if ($blocked > 0) {
            return 'Beta 1.0 com bloqueios';
        }
        if ($score >= 90) {
            return 'Beta 1.0 operacional';
        }
        if ($score >= 70) {
            return 'Beta 1.0 em validação';
        }
        return 'Beta 1.0 em preparação';
    }

    private function check(string $label, string $status, string $message, string $action): array
    {
        return compact('label', 'status', 'message', 'action');
    }

    private function latestHealthCount(array $statuses): int
    {
        if (!$this->tableExists('system_health_checks')) {
            return 0;
        }
        try {
            $placeholders = implode(',', array_fill(0, count($statuses), '?'));
            $sql = "SELECT COUNT(*) FROM system_health_checks h
                    INNER JOIN (
                        SELECT check_key, MAX(id) AS max_id
                        FROM system_health_checks
                        GROUP BY check_key
                    ) latest ON latest.max_id = h.id
                    WHERE h.status IN ({$placeholders})";
            $statement = $this->pdo->prepare($sql);
            $statement->execute($statuses);
            return (int) $statement->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    private function number(string $sql): int
    {
        try {
            return (int) ($this->pdo->query($sql)->fetchColumn() ?: 0);
        } catch (Throwable) {
            return 0;
        }
    }

    private function singleValue(string $sql): ?string
    {
        try {
            $value = $this->pdo->query($sql)->fetchColumn();
            return $value === false ? null : (string) $value;
        } catch (Throwable) {
            return null;
        }
    }

    private function countTable(string $table): int
    {
        if (!$this->tableExists($table)) {
            return 0;
        }
        return $this->number('SELECT COUNT(*) FROM `' . str_replace('`', '', $table) . '`');
    }

    private function countWhere(string $table, string $where): int
    {
        if (!$this->tableExists($table)) {
            return 0;
        }
        try {
            return (int) $this->pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '', $table) . '` WHERE ' . $where)->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    private function tableExists(string $table): bool
    {
        try {
            $statement = $this->pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table');
            $statement->execute(['table' => $table]);
            return (int) $statement->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }
}
