<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Traduz códigos e mensagens operacionais para uma linguagem de decisão.
 *
 * Os dados técnicos continuam preservados no banco e nos detalhes do Super Admin.
 * Esta classe altera somente a apresentação e as mensagens enviadas aos canais.
 */
final class OperationalLanguageService
{
    /** @var array<string,array<string,string>> */
    private const SERVICES = [
        'database' => [
            'label' => 'Dados do sistema',
            'title' => 'O RS Connect não consegue acessar os dados',
            'summary' => 'A conexão com os dados do sistema não foi confirmada.',
            'impact' => 'Telas, mensagens e cadastros podem ficar indisponíveis.',
            'action' => 'Verifique o banco de dados e as credenciais do serviço.',
            'client_title' => 'O RS Connect está temporariamente indisponível',
            'client_summary' => 'Algumas áreas podem não abrir ou atualizar neste momento.',
            'client_impact' => 'O atendimento e os cadastros podem sofrer interrupções.',
            'client_action' => 'A equipe responsável deve acompanhar a normalização do sistema.',
        ],
        'migrations' => [
            'label' => 'Atualização do sistema',
            'title' => 'A atualização do RS Connect está incompleta',
            'summary' => 'Uma etapa obrigatória da atualização ainda não foi concluída.',
            'impact' => 'Alguns recursos podem não aparecer ou funcionar corretamente.',
            'action' => 'Conclua a atualização indicada e verifique novamente.',
            'client_title' => 'Uma atualização do sistema precisa ser concluída',
            'client_summary' => 'Alguns recursos podem ficar temporariamente limitados.',
            'client_impact' => 'Partes do sistema podem não funcionar como esperado.',
            'client_action' => 'A equipe da RS Connect deve concluir a atualização.',
        ],
        'disk' => [
            'label' => 'Espaço do servidor',
            'title' => 'O servidor está com pouco espaço disponível',
            'summary' => 'O espaço livre chegou a um nível que precisa de atenção.',
            'impact' => 'Arquivos, mensagens, backups e registros podem deixar de ser salvos.',
            'action' => 'Libere espaço e revise arquivos antigos, registros e backups locais.',
            'client_title' => 'O espaço do sistema está quase cheio',
            'client_summary' => 'O armazenamento disponível está abaixo do recomendado.',
            'client_impact' => 'Arquivos e atualizações podem parar de ser salvos.',
            'client_action' => 'A equipe responsável deve liberar espaço no servidor.',
        ],
        'evolution' => [
            'label' => 'WhatsApp',
            'title' => 'WhatsApp desconectado',
            'summary' => 'A conexão do WhatsApp não foi confirmada.',
            'impact' => 'Mensagens podem parar de entrar ou sair.',
            'action' => 'Abra as conexões de WhatsApp e faça a reconexão.',
            'client_title' => 'WhatsApp desconectado',
            'client_summary' => 'A conexão do WhatsApp precisa ser restabelecida.',
            'client_impact' => 'Mensagens podem não chegar ou não ser enviadas.',
            'client_action' => 'Abra o menu WhatsApp e reconecte o número.',
        ],
        'openai' => [
            'label' => 'Assistente virtual',
            'title' => 'O assistente virtual não consegue responder',
            'summary' => 'A última tentativa de resposta automática não foi concluída.',
            'impact' => 'Conversas podem ficar aguardando atendimento humano.',
            'action' => 'Revise a credencial, o saldo e a configuração do assistente.',
            'client_title' => 'O assistente virtual precisa de atenção',
            'client_summary' => 'As respostas automáticas podem estar temporariamente paradas.',
            'client_impact' => 'Alguns contatos podem ficar aguardando uma resposta.',
            'client_action' => 'Revise a configuração do assistente ou assuma o atendimento.',
        ],
        'ai_reprocess' => [
            'label' => 'Respostas automáticas pendentes',
            'title' => 'Há mensagens aguardando uma nova tentativa',
            'summary' => 'Algumas respostas automáticas ainda não foram concluídas.',
            'impact' => 'Contatos podem permanecer aguardando retorno.',
            'action' => 'Corrija a causa indicada e tente novamente somente depois.',
            'client_title' => 'Há respostas automáticas pendentes',
            'client_summary' => 'Algumas conversas ainda aguardam processamento.',
            'client_impact' => 'Contatos podem ficar sem resposta automática.',
            'client_action' => 'Revise a fila e assuma as conversas urgentes.',
        ],
        'after_hours_recovery' => [
            'label' => 'Mensagens fora do horário',
            'title' => 'Mensagens fora do horário aguardam processamento',
            'summary' => 'Há atendimentos preservados para a próxima janela válida.',
            'impact' => 'Essas conversas podem aguardar até o início do expediente.',
            'action' => 'Confira o horário de atendimento e as conversas pendentes.',
            'client_title' => 'Há mensagens aguardando o horário de atendimento',
            'client_summary' => 'As conversas foram preservadas para o próximo período válido.',
            'client_impact' => 'O retorno automático pode acontecer somente no expediente.',
            'client_action' => 'Confira o horário configurado e as conversas urgentes.',
        ],
        'n8n' => [
            'label' => 'Automações',
            'title' => 'Uma automação não foi concluída',
            'summary' => 'Uma ou mais rotinas automáticas terminaram sem confirmação de sucesso.',
            'impact' => 'Agendamentos, cobranças ou outras tarefas podem ficar paradas.',
            'action' => 'Abra as automações e confira a última execução com problema.',
            'client_title' => 'Uma tarefa automática não foi concluída',
            'client_summary' => 'Uma rotina do sistema precisa ser revisada.',
            'client_impact' => 'Agendamentos, cobranças ou avisos podem sofrer atraso.',
            'client_action' => 'A equipe responsável deve revisar a automação.',
        ],
        'webhooks' => [
            'label' => 'Recebimento de atualizações',
            'title' => 'O RS Connect parou de receber atualizações',
            'summary' => 'Nenhuma atualização externa chegou dentro do tempo esperado.',
            'impact' => 'Mensagens e mudanças de outros serviços podem não aparecer.',
            'action' => 'Confira o serviço que envia as atualizações ao RS Connect.',
            'client_title' => 'O RS Connect não está recebendo atualizações',
            'client_summary' => 'Mensagens ou mudanças externas podem estar atrasadas.',
            'client_impact' => 'Informações novas podem demorar para aparecer.',
            'client_action' => 'A equipe responsável deve revisar o recebimento das atualizações.',
        ],
        'message_queue' => [
            'label' => 'Envio de mensagens',
            'title' => 'Há mensagens aguardando envio',
            'summary' => 'Algumas mensagens ainda não tiveram o envio confirmado.',
            'impact' => 'Os contatos podem não receber o retorno esperado.',
            'action' => 'Revise a conexão do WhatsApp e as mensagens pendentes.',
            'client_title' => 'Há mensagens aguardando envio',
            'client_summary' => 'Alguns envios ainda não foram confirmados.',
            'client_impact' => 'Contatos podem ficar sem receber a mensagem.',
            'client_action' => 'Confira o WhatsApp e tente novamente após a reconexão.',
        ],
        'calendar' => [
            'label' => 'Agenda',
            'title' => 'A agenda não está atualizando corretamente',
            'summary' => 'A última atualização ou sincronização da agenda não foi confirmada.',
            'impact' => 'Horários e compromissos podem aparecer desatualizados.',
            'action' => 'Abra a agenda e confira a conexão e a última atualização.',
            'client_title' => 'A agenda precisa de atenção',
            'client_summary' => 'Horários ou compromissos podem estar desatualizados.',
            'client_impact' => 'A disponibilidade exibida pode não refletir a agenda atual.',
            'client_action' => 'Confira a agenda antes de confirmar novos horários.',
        ],
        'payments' => [
            'label' => 'Financeiro',
            'title' => 'Atualizações financeiras não foram confirmadas',
            'summary' => 'O sistema não confirmou a atualização mais recente de cobrança ou pagamento.',
            'impact' => 'Situações de pagamento podem aparecer desatualizadas.',
            'action' => 'Revise o meio de pagamento e as últimas atualizações financeiras.',
            'client_title' => 'O financeiro precisa de conferência',
            'client_summary' => 'Uma atualização de cobrança ou pagamento ainda não foi confirmada.',
            'client_impact' => 'O status financeiro pode demorar para atualizar.',
            'client_action' => 'Confira os dados da cobrança e aguarde a confirmação.',
        ],
        'billing_cron' => [
            'label' => 'Lembretes de cobrança',
            'title' => 'Os lembretes de cobrança não foram processados',
            'summary' => 'A rotina de cobrança não apresentou uma conclusão recente.',
            'impact' => 'Avisos de vencimento podem não ser enviados no horário esperado.',
            'action' => 'Confira a rotina automática de cobrança e a última execução.',
            'client_title' => 'Os lembretes de cobrança podem estar atrasados',
            'client_summary' => 'A rotina de envio precisa ser conferida.',
            'client_impact' => 'Alguns avisos financeiros podem não ter sido enviados.',
            'client_action' => 'Revise as cobranças pendentes antes de reenviar avisos.',
        ],
        'reporting' => [
            'label' => 'Relatórios',
            'title' => 'Os relatórios podem estar desatualizados',
            'summary' => 'A atualização dos indicadores não foi confirmada dentro do prazo esperado.',
            'impact' => 'Os números exibidos podem não incluir as atividades mais recentes.',
            'action' => 'Atualize os indicadores e confira novamente o período.',
            'client_title' => 'Os relatórios podem estar desatualizados',
            'client_summary' => 'As atividades mais recentes talvez ainda não apareçam.',
            'client_impact' => 'Os totais podem mudar após a próxima atualização.',
            'client_action' => 'Atualize a página mais tarde ou solicite uma nova apuração.',
        ],
        'backup' => [
            'label' => 'Cópia de segurança',
            'title' => 'A cópia de segurança precisa de atenção',
            'summary' => 'A última cópia de segurança não foi confirmada dentro do prazo esperado.',
            'impact' => 'A recuperação de dados fica mais arriscada enquanto não houver uma cópia atual.',
            'action' => 'Execute a rotina de cópia de segurança e confirme o resultado.',
            'client_title' => 'A cópia de segurança precisa ser atualizada',
            'client_summary' => 'A última cópia válida está atrasada ou não foi confirmada.',
            'client_impact' => 'A recuperação de dados pode ficar mais limitada.',
            'client_action' => 'A equipe responsável deve gerar e validar uma nova cópia.',
        ],
        'security' => [
            'label' => 'Acesso e segurança',
            'title' => 'Um acesso ou configuração de segurança precisa de revisão',
            'summary' => 'O sistema encontrou uma situação de acesso que merece conferência.',
            'impact' => 'Um usuário pode ficar sem acesso ou uma proteção pode não estar completa.',
            'action' => 'Abra usuários e segurança para revisar a situação indicada.',
            'client_title' => 'Um acesso precisa de revisão',
            'client_summary' => 'Há uma situação de acesso ou segurança que precisa ser conferida.',
            'client_impact' => 'Um usuário pode não conseguir entrar ou usar algum recurso.',
            'client_action' => 'Peça ao administrador da empresa para revisar os acessos.',
        ],
        'access' => [
            'label' => 'Acesso da empresa',
            'title' => 'O acesso da empresa precisa de revisão',
            'summary' => 'A liberação atual da empresa não foi confirmada.',
            'impact' => 'Usuários podem encontrar bloqueio ao entrar ou usar recursos.',
            'action' => 'Revise a assinatura, a situação financeira e a liberação da empresa.',
            'client_title' => 'O acesso da empresa precisa de revisão',
            'client_summary' => 'A conta pode estar com uma pendência de acesso.',
            'client_impact' => 'Algumas áreas podem ficar bloqueadas.',
            'client_action' => 'Fale com o administrador da empresa ou com o suporte da RS Connect.',
        ],
        'generic' => [
            'label' => 'Funcionamento do sistema',
            'title' => 'Uma situação precisa de atenção',
            'summary' => 'O RS Connect encontrou algo que precisa ser conferido.',
            'impact' => 'Uma parte do sistema pode não funcionar como esperado.',
            'action' => 'Abra os detalhes e siga a orientação exibida.',
            'client_title' => 'Uma situação precisa de atenção',
            'client_summary' => 'O sistema encontrou algo que precisa ser conferido.',
            'client_impact' => 'Uma função pode ficar temporariamente limitada.',
            'client_action' => 'Abra os detalhes ou fale com o administrador da empresa.',
        ],
    ];

    /** @return array<string,string> */
    public static function service(string $key, bool $client = false): array
    {
        $key = self::normalizeKey($key);
        $service = self::SERVICES[$key] ?? self::SERVICES['generic'];
        if (!$client) {
            return [
                'key' => $key,
                'label' => $service['label'],
                'title' => $service['title'],
                'summary' => $service['summary'],
                'impact' => $service['impact'],
                'action' => $service['action'],
            ];
        }

        return [
            'key' => $key,
            'label' => $service['label'],
            'title' => $service['client_title'],
            'summary' => $service['client_summary'],
            'impact' => $service['client_impact'],
            'action' => $service['client_action'],
        ];
    }

    /** @param array<string,mixed> $record @return array<string,string> */
    public static function incident(array $record, bool $client = false, ?string $kind = null): array
    {
        $technicalTitle = trim((string) ($record['title'] ?? $record['event'] ?? $record['label'] ?? ''));
        $technicalMessage = trim((string) ($record['message'] ?? $record['summary'] ?? $record['error_message'] ?? ''));
        $event = trim((string) ($record['event'] ?? $record['component_key'] ?? $record['check_key'] ?? ''));
        $key = self::detectKey($event, $technicalTitle, $technicalMessage, (string) ($record['action_url'] ?? $record['related_url'] ?? ''));
        $service = self::service($key, $client);
        $severity = strtolower(trim((string) ($record['severity'] ?? $record['status'] ?? 'warning')));
        $status = strtolower(trim((string) ($record['status'] ?? 'open')));
        $kind = strtolower(trim((string) ($kind ?? $record['notification_kind'] ?? '')));
        $resolved = in_array($kind, ['recovered', 'resolved', 'auto_resolved'], true)
            || in_array($status, ['resolved', 'recovered', 'success', 'ok', 'healthy', 'operational'], true)
            || !empty($record['resolved_at']);

        $title = $service['title'];
        $summary = self::specificSummary($key, $technicalMessage, $service['summary']);
        $impact = $service['impact'];
        $action = $service['action'];

        if ($resolved) {
            $title = $service['label'] . ' voltou ao normal';
            $summary = 'A verificação mais recente confirmou que a situação foi normalizada.';
            $impact = 'O funcionamento esperado foi restabelecido.';
            $action = 'Nenhuma ação é necessária agora. Continue acompanhando normalmente.';
        }

        return [
            'key' => $key,
            'label' => $service['label'],
            'title' => $title,
            'summary' => $summary,
            'impact' => $impact,
            'action' => $action,
            'severity_label' => self::severityLabel($severity),
            'status_label' => self::statusLabel($status, $resolved),
            'technical_title' => $technicalTitle,
            'technical_message' => $technicalMessage,
            'technical_event' => $event,
        ];
    }

    /** @param array<string,mixed> $notification @return array<string,string> */
    public static function notification(array $notification, bool $client = false): array
    {
        $title = trim((string) ($notification['title'] ?? ''));
        $message = trim((string) ($notification['message'] ?? ''));
        $event = trim((string) ($notification['event'] ?? ''));
        $actionUrl = trim((string) ($notification['action_url'] ?? ''));
        $operational = self::looksOperational($title . ' ' . $message . ' ' . $event . ' ' . $actionUrl);

        if (!$operational) {
            return [
                'key' => 'generic',
                'label' => '',
                'title' => self::replaceTechnicalTerms($title),
                'summary' => self::replaceTechnicalTerms($message),
                'impact' => '',
                'action' => '',
                'severity_label' => self::severityLabel((string) ($notification['severity'] ?? 'info')),
                'status_label' => self::statusLabel((string) ($notification['status'] ?? 'read')),
                'technical_title' => $title,
                'technical_message' => $message,
                'technical_event' => $event,
            ];
        }

        return self::incident($notification, $client, (string) ($notification['notification_kind'] ?? ''));
    }

    /** @param array<string,mixed> $check @return array<string,string> */
    public static function check(array $check, bool $client = false): array
    {
        $key = self::detectKey(
            (string) ($check['check_key'] ?? $check['component_key'] ?? ''),
            (string) ($check['label'] ?? $check['component_label'] ?? ''),
            (string) ($check['message'] ?? $check['summary'] ?? ''),
            (string) ($check['route'] ?? $check['action_url'] ?? '')
        );
        $service = self::service($key, $client);
        $rawStatus = strtolower((string) ($check['status'] ?? 'unknown'));
        $healthy = in_array($rawStatus, ['ok', 'success', 'healthy', 'operational', 'info'], true);

        return [
            'key' => $key,
            'label' => $service['label'],
            'title' => $healthy ? $service['label'] . ' funcionando normalmente' : $service['title'],
            'summary' => $healthy
                ? self::healthySummary($key, (string) ($check['message'] ?? $check['summary'] ?? ''))
                : self::specificSummary($key, (string) ($check['message'] ?? $check['summary'] ?? ''), $service['summary']),
            'impact' => $healthy ? 'Nenhum impacto identificado na última verificação.' : $service['impact'],
            'action' => $healthy ? 'Nenhuma ação necessária.' : $service['action'],
            'severity_label' => self::severityLabel($rawStatus),
            'status_label' => self::statusLabel($rawStatus, $healthy),
            'technical_title' => trim((string) ($check['label'] ?? $check['component_label'] ?? '')),
            'technical_message' => trim((string) ($check['message'] ?? $check['summary'] ?? '')),
            'technical_event' => trim((string) ($check['check_key'] ?? $check['component_key'] ?? '')),
        ];
    }

    public static function severityLabel(string $severity): string
    {
        return match (strtolower(trim($severity))) {
            'critical', 'danger', 'down' => 'Ação imediata',
            'error', 'failed' => 'Não foi possível concluir',
            'warning', 'attention', 'blocked' => 'Precisa de atenção',
            'success', 'ok', 'healthy', 'operational' => 'Tudo normal',
            'info', 'neutral' => 'Informação',
            'unknown' => 'Ainda não verificado',
            default => 'Acompanhar',
        };
    }

    public static function statusLabel(string $status, bool $resolved = false): string
    {
        if ($resolved) {
            return 'Tudo normal novamente';
        }

        return match (strtolower(trim($status))) {
            'open', 'unread', 'active' => 'Aguardando análise',
            'acknowledged' => 'Em análise',
            'monitoring' => 'Acompanhando',
            'resolved', 'recovered', 'success', 'ok', 'healthy', 'operational', 'sent' => 'Tudo normal',
            'read' => 'Visualizado',
            'archived' => 'Arquivado',
            'pending', 'queued' => 'Aguardando',
            'pending_configuration' => 'Falta configurar',
            'error', 'failed', 'down' => 'Não foi possível concluir',
            'warning', 'attention', 'blocked' => 'Precisa de atenção',
            'running', 'processing' => 'Em andamento',
            'unknown' => 'Ainda não verificado',
            'skipped' => 'Não se aplica',
            default => 'Acompanhar',
        };
    }

    public static function channelLabel(string $channel): string
    {
        return match (strtolower(trim($channel))) {
            'platform' => 'RS Connect',
            'whatsapp' => 'WhatsApp',
            'email' => 'E-mail',
            default => self::replaceTechnicalTerms($channel),
        };
    }

    public static function notificationKindLabel(string $kind): string
    {
        return match (strtolower(trim($kind))) {
            'opened' => 'Novo aviso',
            'reminder' => 'Continua precisando de atenção',
            'recovered' => 'Tudo normal novamente',
            'manual' => 'Teste dos avisos',
            'client_communication' => 'Comunicado ao cliente',
            default => 'Atualização',
        };
    }

    public static function monitorRunLabel(string $status): string
    {
        return match (strtolower(trim($status))) {
            'success', 'ok', 'completed' => 'Verificação concluída',
            'running', 'processing' => 'Verificação em andamento',
            'error', 'failed' => 'Verificação não concluída',
            default => 'Verificação registrada',
        };
    }

    public static function triggerLabel(string $source): string
    {
        return match (strtolower(trim($source))) {
            'manual' => 'Iniciada manualmente',
            'cron', 'n8n', 'webhook', 'cli', 'automatic' => 'Executada automaticamente',
            default => 'Origem não identificada',
        };
    }

    /** @param array<string,mixed> $presentation */
    public static function alertMessage(array $presentation, string $tenantName = '', string $kind = 'opened'): string
    {
        $lines = [];
        if ($tenantName !== '') {
            $lines[] = 'Empresa: ' . $tenantName;
            $lines[] = '';
        }
        $lines[] = 'O que aconteceu:';
        $lines[] = (string) ($presentation['summary'] ?? '');
        $lines[] = '';
        $lines[] = 'O que pode ser afetado:';
        $lines[] = (string) ($presentation['impact'] ?? '');
        $lines[] = '';
        $lines[] = 'O que fazer agora:';
        $lines[] = (string) ($presentation['action'] ?? '');
        $lines[] = '';
        $lines[] = 'Situação: ' . self::notificationKindLabel($kind);
        return trim(implode("\n", $lines));
    }

    public static function replaceTechnicalTerms(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $replacements = [
            '/\bwebhook\s+n8n\b|\bn8n\s+webhook\b/iu' => 'automação',
            '/\bautomação\s+falhou\b/iu' => 'automação não foi concluída',
            '/\btoken\s+inválido\b/iu' => 'chave de segurança inválida',
            '/\bAPI\s*key\s+inválida\b/iu' => 'chave de acesso inválida',
            '/WhatsApp\s*\/\s*Evolution|Evolution\s*API|Evolution/iu' => 'WhatsApp',
            '/OpenAI\s*\/\s*IA|OpenAI|Gemini/iu' => 'assistente virtual',
            '/\bn8n\b/iu' => 'automação',
            '/\bwebhooks?\b/iu' => 'recebimento de atualizações',
            '/\bcallbacks?\b/iu' => 'retorno da integração',
            '/\bmigrations?\b/iu' => 'atualizações do sistema',
            '/\bcron\b/iu' => 'rotina automática',
            '/\bendpoint\b/iu' => 'endereço do serviço',
            '/\bprovider\b/iu' => 'serviço externo',
            '/\bdegraded\b|\bdegradad[oa]s?\b/iu' => 'funcionando parcialmente',
            '/\bfailed\b|\bfailure\b/iu' => 'não concluído',
            '/\bfalhou\b/iu' => 'não foi concluído',
            '/\bfalhas\b/iu' => 'problemas',
            '/\bfalha\b/iu' => 'problema',
            '/\berrors?\b/iu' => 'problema',
            '/\berros\b/iu' => 'problemas',
            '/\berro\b/iu' => 'problema',
            '/\bincidentes\b/iu' => 'situações',
            '/\bincidente\b/iu' => 'situação',
            '/\bcrític[oa]s?\b/iu' => 'urgente',
            '/\bAPI\s*key\b/iu' => 'chave de acesso',
            '/\btoken\b/iu' => 'chave de segurança',
            '/HTTP\s*401/iu' => 'acesso não autorizado',
            '/HTTP\s*403/iu' => 'acesso recusado',
            '/HTTP\s*404/iu' => 'endereço não encontrado',
            '/HTTP\s*429/iu' => 'limite temporário atingido',
            '/HTTP\s*5\d\d/iu' => 'serviço temporariamente indisponível',
            '/SQLSTATE\[[^\]]+\][^:]*:?/iu' => 'Não foi possível salvar os dados:',
            '/invalid parameter number/iu' => 'os dados enviados não puderam ser processados',
            '/bad request/iu' => 'solicitação não aceita',
            '/instance is not connected/iu' => 'o WhatsApp está desconectado',
            '/\binstance\b/iu' => 'conexão',
            '/\btenant\b/iu' => 'empresa',
            '/\bpayload\b/iu' => 'informações enviadas',
            '/\bworker\b/iu' => 'rotina automática',
            '/\bqueue\b/iu' => 'fila',
            '/\btelemetria\b/iu' => 'dados de uso',
            '/\bsnapshots?\b/iu' => 'registros mensais',
        ];
        $result = preg_replace(array_keys($replacements), array_values($replacements), $text) ?? $text;
        return trim(preg_replace('/\s+/', ' ', strip_tags($result)) ?? $result);
    }

    private static function normalizeKey(string $key): string
    {
        $key = strtolower(trim($key));
        if (str_starts_with($key, 'operations.alert.')) {
            $key = substr($key, strlen('operations.alert.'));
        }
        if (str_starts_with($key, 'backup.')) {
            return 'backup';
        }
        if (str_starts_with($key, 'evolution.') || str_starts_with($key, 'instance.')) {
            return 'evolution';
        }
        if (str_starts_with($key, 'ai.') || str_contains($key, 'openai')) {
            return 'openai';
        }
        if (str_starts_with($key, 'calendar.')) {
            return 'calendar';
        }
        if (str_starts_with($key, 'security.')) {
            return 'security';
        }
        if (str_starts_with($key, 'access')) {
            return 'access';
        }
        return isset(self::SERVICES[$key]) ? $key : 'generic';
    }

    private static function detectKey(string ...$parts): string
    {
        $joined = self::fold(implode(' ', $parts));
        $patterns = [
            'database' => ['operations.alert.database', 'banco de dados', 'database', 'db_'],
            'migrations' => ['operations.alert.migrations', 'migration', 'estrutura obrigatoria', 'tabelas ausentes'],
            'disk' => ['operations.alert.disk', 'espaco em disco', 'disk', 'armazenamento'],
            'evolution' => ['operations.alert.evolution', 'evolution', 'whatsapp', 'instance.', '/instances'],
            'openai' => ['operations.alert.openai', 'openai', 'gemini', 'assistente virtual', 'ai.failed', 'credencial de ia', '/ai-credentials'],
            'ai_reprocess' => ['operations.alert.ai_reprocess', 'ai_reprocess', 'fila da ia', 'reprocess'],
            'after_hours_recovery' => ['operations.alert.after_hours_recovery', 'after_hours', 'pos-horario', 'fora do horario'],
            'n8n' => ['operations.alert.n8n', 'n8n', 'automacao', 'flow'],
            'webhooks' => ['operations.alert.webhooks', 'webhook', 'callback', 'recebimento de atualizacoes'],
            'message_queue' => ['operations.alert.message_queue', 'message_queue', 'fila de mensagens', 'mensagens pendentes', 'falha de envio'],
            'calendar' => ['operations.alert.calendar', 'google agenda', 'google calendar', 'calendar', 'agenda'],
            'payments' => ['operations.alert.payments', 'payment', 'pagamento', 'gateway', 'financeiro'],
            'billing_cron' => ['operations.alert.billing_cron', 'billing_cron', 'cron de cobranca', 'regua de cobranca', 'lembrete de cobranca'],
            'reporting' => ['operations.alert.reporting', 'reporting', 'relatorio', 'agregacao'],
            'backup' => ['operations.alert.backup', 'backup', 'copia de seguranca'],
            'security' => ['security', 'usuario bloqueado', 'login', '/users'],
            'access' => ['acesso da empresa', 'assinatura', 'subscription', '/billing'],
        ];

        foreach ($patterns as $key => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($joined, self::fold($needle))) {
                    return $key;
                }
            }
        }
        return 'generic';
    }

    private static function specificSummary(string $key, string $raw, string $fallback): string
    {
        $plain = self::replaceTechnicalTerms($raw);
        $folded = self::fold($raw);
        if ($plain === '') {
            return $fallback;
        }

        if ($key === 'evolution') {
            if (preg_match('/(\d+)\s*\/\s*(\d+).*inst[aâá]ncia/iu', $raw, $match) === 1) {
                $connected = (int) $match[1];
                $total = (int) $match[2];
                if ($connected < $total) {
                    $noun = $total === 1 ? 'conexão' : 'conexões';
                    $verb = $total === 1 ? 'está ativa' : 'estão ativas';
                    return $connected . ' de ' . $total . ' ' . $noun . ' do WhatsApp ' . $verb . '.';
                }
            }
            if (str_contains($folded, 'envio mais recente') || str_contains($folded, 'falhou')) {
                return 'A conexão aparece ativa, mas o envio mais recente não foi concluído.';
            }
            if (str_contains($folded, 'nenhuma instancia') || str_contains($folded, 'desconect')) {
                return 'Nenhuma conexão do WhatsApp foi confirmada como ativa.';
            }
        }

        if ($key === 'n8n') {
            if (preg_match('/(\d+)\s+falha\(s\) consecutiva/iu', $raw, $match) === 1) {
                return 'A automação apresentou ' . (int) $match[1] . ' tentativas seguidas sem conclusão.';
            }
            if (str_contains($folded, 'nenhum fluxo') || str_contains($folded, 'nao ha sucesso')) {
                return 'As automações não possuem uma conclusão recente confirmada.';
            }
        }

        if ($key === 'message_queue' && preg_match('/(\d+)\s+mensagem/iu', $raw, $match) === 1) {
            return (int) $match[1] . ' mensagem(ns) ainda aguardam confirmação de envio.';
        }

        if ($key === 'disk' && preg_match('/(\d+(?:[\.,]\d+)?)\s*%/u', $raw, $match) === 1) {
            return 'O servidor está com aproximadamente ' . str_replace('.', ',', $match[1]) . '% de espaço livre.';
        }

        if ($key === 'openai') {
            if (str_contains($folded, 'nenhuma credencial') || str_contains($folded, 'api key')) {
                return 'O assistente virtual está sem uma credencial válida para responder.';
            }
            if (str_contains($folded, 'quota') || str_contains($folded, 'saldo') || str_contains($folded, 'limit')) {
                return 'O assistente virtual atingiu um limite de uso ou está sem saldo disponível.';
            }
            if (str_contains($folded, 'falha')) {
                return 'A tentativa mais recente do assistente virtual não foi concluída.';
            }
        }

        if ($key === 'webhooks' && (str_contains($folded, 'nenhum') || str_contains($folded, 'nenhuma') || str_contains($folded, 'inatividade'))) {
            return 'O RS Connect não recebeu novas atualizações dentro do tempo esperado.';
        }

        if ($key === 'backup' && (str_contains($folded, 'nenhum') || str_contains($folded, 'atras') || str_contains($folded, 'idade'))) {
            return 'A última cópia de segurança válida está atrasada ou ainda não foi confirmada.';
        }

        if ($key === 'migrations') {
            return 'Uma etapa obrigatória da atualização do RS Connect ainda não foi concluída.';
        }

        if ($key === 'database' && (str_contains($folded, 'falha') || str_contains($folded, 'nao foi possivel'))) {
            return 'O RS Connect não conseguiu acessar os dados necessários para funcionar.';
        }

        if (self::length($plain) <= 180 && !self::looksVeryTechnical($raw)) {
            return $plain;
        }
        return $fallback;
    }

    private static function healthySummary(string $key, string $raw): string
    {
        $plain = self::replaceTechnicalTerms($raw);
        if ($plain !== '' && self::length($plain) <= 180 && !self::looksVeryTechnical($raw)) {
            return $plain;
        }
        return match ($key) {
            'database' => 'O acesso aos dados foi confirmado.',
            'migrations' => 'A atualização necessária está completa.',
            'disk' => 'O servidor possui espaço disponível dentro do esperado.',
            'evolution' => 'A conexão do WhatsApp foi confirmada.',
            'openai' => 'O assistente virtual apresentou uma resposta concluída recentemente.',
            'n8n' => 'As automações possuem uma execução recente concluída.',
            'webhooks' => 'O RS Connect está recebendo atualizações normalmente.',
            'message_queue' => 'Não há acúmulo relevante de mensagens aguardando envio.',
            'calendar' => 'A agenda apresentou atualização recente.',
            'payments' => 'As atualizações financeiras estão sendo recebidas.',
            'billing_cron' => 'A rotina de lembretes foi executada dentro do esperado.',
            'ai_reprocess' => 'A fila de respostas automáticas está sob controle.',
            'after_hours_recovery' => 'A rotina de mensagens fora do horário está funcionando.',
            'reporting' => 'Os indicadores possuem atualização recente.',
            'backup' => 'Há uma cópia de segurança válida e atual.',
            default => 'A última verificação foi concluída sem problemas.',
        };
    }

    private static function looksOperational(string $text): bool
    {
        $folded = self::fold($text);
        foreach (['operacao-alertas', 'operations.alert', 'incidente', 'evolution', 'openai', 'n8n', 'webhook', 'callback', 'migration', 'backup', 'falha', 'erro', 'failed', 'down', 'degraded', 'fila da ia', 'espaco em disco'] as $needle) {
            if (str_contains($folded, self::fold($needle))) {
                return true;
            }
        }
        return false;
    }

    private static function looksVeryTechnical(string $text): bool
    {
        return preg_match('/<html|<style|\bSQLSTATE\b|\bPDO\b|\bexception\b|\bstack trace\b|\bHTTP\s*\d{3}\b|\/var\/www|\/app\/|\bSELECT\b|\bINSERT\b|\bUPDATE\b|\bDELETE\b/iu', $text) === 1
            || self::length($text) > 260;
    }

    private static function fold(string $text): string
    {
        $text = trim($text);
        $text = function_exists('mb_strtolower') ? mb_strtolower($text) : strtolower($text);
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        return $converted !== false ? $converted : $text;
    }
    private static function length(string $text): int
    {
        return function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
    }

}
