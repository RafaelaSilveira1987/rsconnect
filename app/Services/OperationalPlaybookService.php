<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

final class OperationalPlaybookService
{
    public function diagnose(string $key, int $tenantId = 0): array
    {
        $key = $this->normalizeKey($key);
        $evidence = $this->latestEvidence($key);
        $detail = strtolower((string) ($evidence['message'] ?? ''));
        $variant = $this->variant($key, $detail);
        $base = $this->playbooks()[$variant] ?? $this->playbooks()[$key] ?? $this->playbooks()['generic'];
        $base['key'] = $key;
        $base['variant'] = $variant;
        $base['tenant_id'] = $tenantId;
        $base['tenant'] = $tenantId > 0 ? $this->tenant($tenantId) : null;
        $base['evidence'] = $evidence;
        return $base;
    }

    public function centralUrl(string $key, int $tenantId = 0): string
    {
        $query = 'diagnostico=' . rawurlencode($this->normalizeKey($key));
        if ($tenantId > 0) $query .= '&tenant=' . $tenantId;
        return '/central-operacao?' . $query;
    }

    private function normalizeKey(string $key): string
    {
        $key = trim($key);
        if (str_starts_with($key, 'service-')) $key = substr($key, 8);
        if (str_starts_with($key, 'external-evolution')) return 'evolution';
        return $key !== '' ? $key : 'generic';
    }

    private function latestEvidence(string $key): array
    {
        try {
            $st = Database::connection()->prepare('SELECT * FROM system_health_checks WHERE check_key = :key ORDER BY id DESC LIMIT 1');
            $st->execute(['key' => $key]);
            return $st->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) { return []; }
    }

    private function tenant(int $id): ?array
    {
        try {
            $st = Database::connection()->prepare('SELECT id, name, email, phone, commercial_whatsapp FROM tenants WHERE id = :id LIMIT 1');
            $st->execute(['id' => $id]);
            return $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable) { return null; }
    }

    private function variant(string $key, string $detail): string
    {
        if ($key === 'openai') {
            if (str_contains($detail, '401')) return 'openai_401';
            if (str_contains($detail, '402') || str_contains($detail, 'quota') || str_contains($detail, 'credit')) return 'openai_quota';
            if (str_contains($detail, '429')) return 'openai_429';
            if (str_contains($detail, '403')) return 'openai_403';
        }
        if ($key === 'backup') {
            if (str_contains($detail, 'permission denied')) return 'backup_permission';
            if (str_contains($detail, 'no space left') || str_contains($detail, 'disk full')) return 'backup_space';
            if (str_contains($detail, 'mysqldump')) return 'backup_mysql';
            if (str_contains($detail, 'callback')) return 'backup_callback';
        }
        if ($key === 'n8n' && (str_contains($detail, 'token') || str_contains($detail, 'callback'))) return 'n8n_token';
        return $key;
    }

    private function playbooks(): array
    {
        return [
            'generic' => ['title'=>'Diagnóstico operacional','cause'=>'O monitoramento encontrou uma evidência que precisa de revisão.','impact'=>'O impacto depende do serviço afetado.','steps'=>['Revise a evidência técnica abaixo.','Abra a ferramenta relacionada e confirme credenciais, conexão e última execução.','Execute uma nova verificação após a correção.'],'actions'=>[['label'=>'Voltar ao painel','url'=>'/painel-operacional']]],
            'evolution' => ['title'=>'WhatsApp / Evolution desconectado','cause'=>'A Evolution não confirmou uma conexão ativa para a instância.','impact'=>'Mensagens ficam preservadas, mas o atendimento automático não consegue prosseguir.','steps'=>['Abra WhatsApp e localize a instância afetada.','Gere ou confira o QR Code e aguarde o estado conectado.','Volte à Fila da IA e reprocese as mensagens preservadas.'],'actions'=>[['label'=>'Abrir WhatsApp','url'=>'/instances'],['label'=>'Ver fila da IA','url'=>'/central-operacao?tab=ai_reprocess']]],
            'n8n' => ['title'=>'Falha de integração com n8n','cause'=>'O endpoint ou o fluxo n8n não confirmou uma execução válida.','impact'=>'Automações e callbacks podem ficar interrompidos.','steps'=>['Confirme se o workflow está ativo.','Revise a URL do webhook e a última execução no n8n.','Teste o fluxo pelo módulo n8n e valide o callback.'],'actions'=>[['label'=>'Abrir n8n','url'=>'/n8n'],['label'=>'Ver fluxos','url'=>'/n8n-flows']]],
            'n8n_token' => ['title'=>'Token/callback do n8n requer revisão','cause'=>'A evidência indica falha relacionada ao callback ou autenticação do fluxo.','impact'=>'O n8n pode executar, mas o RS Connect não consegue validar ou receber o retorno.','steps'=>['Confirme N8N_CALLBACK_TOKEN no ambiente.','Compare o token configurado no workflow com o RS Connect.','Garanta que o workflow esteja ativo e teste novamente o callback.'],'actions'=>[['label'=>'Abrir fluxos n8n','url'=>'/n8n-flows'],['label'=>'Abrir n8n','url'=>'/n8n']]],
            'openai' => ['title'=>'IA / OpenAI requer revisão','cause'=>'O provedor de IA não confirmou uma resposta válida.','impact'=>'Assistentes podem deixar de responder até a credencial/provedor voltar ao normal.','steps'=>['Abra IA e credenciais.','Revise a última falha do provedor.','Corrija a credencial/limite e reprocese a fila pendente.'],'actions'=>[['label'=>'Abrir credenciais IA','url'=>'/ai-credentials'],['label'=>'Ver fila da IA','url'=>'/central-operacao?tab=ai_reprocess']]],
            'openai_401' => ['title'=>'OpenAI: chave inválida ou revogada','cause'=>'Retorno HTTP 401 do provedor.','impact'=>'As respostas de IA ficam indisponíveis para a credencial afetada.','steps'=>['Confirme a chave cadastrada.','Troque uma chave revogada ou incorreta.','Teste novamente e reprocese a fila.'],'actions'=>[['label'=>'Abrir credenciais IA','url'=>'/ai-credentials']]],
            'openai_quota' => ['title'=>'OpenAI: crédito ou quota indisponível','cause'=>'O retorno indica limite financeiro/quota da conta.','impact'=>'A IA não consegue gerar novas respostas até a conta voltar a ter capacidade.','steps'=>['Confira faturamento/limites no provedor.','Regularize crédito ou limite da conta.','Execute nova verificação antes de reprocessar a fila.'],'actions'=>[['label'=>'Abrir credenciais IA','url'=>'/ai-credentials']]],
            'openai_429' => ['title'=>'OpenAI: limite de requisições atingido','cause'=>'Retorno HTTP 429.','impact'=>'Respostas podem atrasar ou falhar temporariamente.','steps'=>['Confirme o limite da conta/modelo.','Reduza picos de requisições ou aguarde a janela do provedor.','Reprocese apenas as mensagens pendentes após normalização.'],'actions'=>[['label'=>'Abrir credenciais IA','url'=>'/ai-credentials']]],
            'openai_403' => ['title'=>'OpenAI: acesso negado','cause'=>'Retorno HTTP 403 do provedor ou intermediário.','impact'=>'A credencial/rede atual não consegue acessar o recurso solicitado.','steps'=>['Revise organização, projeto, endpoint e permissões da chave.','Confirme se proxy/intermediário não está bloqueando a chamada.','Teste novamente pela tela de credenciais.'],'actions'=>[['label'=>'Abrir credenciais IA','url'=>'/ai-credentials']]],
            'backup' => ['title'=>'Backup requer atenção','cause'=>'A última evidência de backup não atende aos critérios de segurança/recência.','impact'=>'A capacidade de recuperação pode ficar comprometida.','steps'=>['Confira a última execução e o arquivo gerado.','Valide tamanho, local e verificação do backup.','Execute novamente a rotina e confirme o callback.'],'actions'=>[['label'=>'Abrir backups','url'=>'/central-operacao?tab=backups']]],
            'backup_permission' => ['title'=>'Backup: permissão negada','cause'=>'O executor não possui permissão para acessar ou executar o recurso necessário.','impact'=>'O arquivo de backup pode não ser criado.','steps'=>['Confirme usuário/permissões do executor.','Execute o script explicitamente com bash quando aplicável.','Rode novamente e confira o callback.'],'actions'=>[['label'=>'Abrir backups','url'=>'/central-operacao?tab=backups']]],
            'backup_space' => ['title'=>'Backup: VPS sem espaço disponível','cause'=>'A evidência indica falta de espaço em disco.','impact'=>'Novos backups e outros serviços podem falhar.','steps'=>['Confira uso de disco na VPS.','Remova arquivos seguros/antigos ou amplie o volume.','Execute um novo backup e valide o tamanho.'],'actions'=>[['label'=>'Abrir backups','url'=>'/central-operacao?tab=backups']]],
            'backup_mysql' => ['title'=>'Backup: falha no mysqldump','cause'=>'O dump do banco não foi concluído.','impact'=>'O backup pode estar incompleto ou inexistente.','steps'=>['Revise credenciais do banco usadas pelo script.','Teste acesso ao banco a partir do executor.','Execute o dump novamente e valide o arquivo.'],'actions'=>[['label'=>'Abrir backups','url'=>'/central-operacao?tab=backups']]],
            'backup_callback' => ['title'=>'Backup: confirmação/callback ausente','cause'=>'A execução pode ter ocorrido, mas o RS Connect não recebeu confirmação confiável.','impact'=>'O painel não consegue afirmar que existe um backup válido.','steps'=>['Confira a execução no n8n/VPS.','Valide URL e token do callback.','Reenvie/teste o callback e rode nova verificação.'],'actions'=>[['label'=>'Abrir backups','url'=>'/central-operacao?tab=backups'],['label'=>'Abrir n8n','url'=>'/n8n']]],
            'billing_cron' => ['title'=>'Cron de cobrança atrasado','cause'=>'A execução esperada da régua não foi comprovada no período.','impact'=>'Lembretes e atualizações financeiras podem atrasar.','steps'=>['Confira o workflow n8n responsável.','Revise a última execução automática e o token.','Execute a régua manualmente após corrigir a causa.'],'actions'=>[['label'=>'Abrir régua de cobrança','url'=>'/billing-reminders'],['label'=>'Abrir n8n','url'=>'/n8n']]],
            'calendar' => ['title'=>'Google Agenda requer revisão','cause'=>'A sincronização/disponibilidade não possui evidência válida.','impact'=>'Horários podem ficar desatualizados.','steps'=>['Confira a configuração da agenda.','Valide a última sincronização e callback.','Faça um teste de disponibilidade.'],'actions'=>[['label'=>'Abrir agenda inteligente','url'=>'/calendar/availability']]],
            'database' => ['title'=>'Banco de dados requer ação','cause'=>'A aplicação não confirmou uma conexão saudável.','impact'=>'Múltiplos módulos podem ficar indisponíveis.','steps'=>['Revise DB_HOST, DB_DATABASE, DB_USERNAME e DB_PASSWORD.','Confirme alcance do banco a partir da aplicação.','Rode nova verificação após a correção.'],'actions'=>[['label'=>'Status do sistema','url'=>'/central-operacao?tab=status']]],
            'migrations' => ['title'=>'Estrutura/migrations pendentes','cause'=>'A estrutura esperada do pacote não foi encontrada por completo.','impact'=>'Recursos recentes podem falhar ou aparecer incompletos.','steps'=>['Identifique a migration pendente no Status do sistema.','Aplique as migrations em ordem.','Execute nova verificação.'],'actions'=>[['label'=>'Status do sistema','url'=>'/central-operacao?tab=status']]],
            'webhooks' => ['title'=>'Webhooks/mensagens requerem revisão','cause'=>'O tráfego recente não confirmou o comportamento esperado.','impact'=>'Eventos externos podem não chegar ao RS Connect.','steps'=>['Confira mensagens recentes.','Revise endpoints/webhooks configurados.','Envie uma mensagem de teste e valide o registro.'],'actions'=>[['label'=>'Abrir conversas','url'=>'/conversations']]],
            'payments' => ['title'=>'Gateways/pagamentos requerem revisão','cause'=>'A conciliação financeira não possui evidência válida.','impact'=>'Pagamentos podem demorar a refletir no RS Connect.','steps'=>['Confira o gateway configurado.','Revise webhook e eventos recentes.','Atualize/reconcilie a cobrança após validar no provedor.'],'actions'=>[['label'=>'Abrir gateways','url'=>'/payment-gateways']]],
            'ai_reprocess' => ['title'=>'Fila da IA requer revisão','cause'=>'A rotina de reprocessamento não possui evidência válida ou registrou falha.','impact'=>'Mensagens pendentes podem permanecer sem nova tentativa.','steps'=>['Abra a Fila da IA.','Separe bloqueio externo de falha interna.','Corrija a dependência e reprocese apenas as pendências elegíveis.'],'actions'=>[['label'=>'Abrir fila da IA','url'=>'/central-operacao?tab=ai_reprocess']]],
            'reporting' => ['title'=>'Relatórios/agregação requerem revisão','cause'=>'A atualização das métricas está atrasada ou sem evidência.','impact'=>'Indicadores podem ficar defasados.','steps'=>['Confira a última agregação.','Valide a rotina responsável pelas métricas.','Execute/aguarde nova agregação e confira os relatórios.'],'actions'=>[['label'=>'Abrir relatórios','url'=>'/reports']]],
        ];
    }
}
