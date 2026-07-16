<?php

use App\Core\Router;
use App\Core\View;

$clientManuals = [
    [
        'slug' => 'dashboard',
        'code' => 'DB',
        'title' => 'Dashboard',
        'summary' => 'Visão geral do atendimento, oportunidades, compromissos e itens que precisam de atenção.',
        'functions' => ['Acompanhar indicadores principais', 'Ver conversas e compromissos recentes', 'Usar atalhos para os módulos mais acessados'],
        'steps' => [
            'Confira os cards do topo para entender o movimento da operação.',
            'Observe os avisos e itens pendentes antes de iniciar o atendimento.',
            'Use os atalhos dos cards para acessar o módulo relacionado.',
        ],
        'tips' => ['Os números seguem as permissões e os módulos liberados para a sua empresa.'],
        'url' => '/',
    ],
    [
        'slug' => 'primeiros-passos',
        'code' => 'PP',
        'title' => 'Primeiros passos',
        'summary' => 'Guia inicial para revisar os dados da empresa, conectar o WhatsApp e preparar o atendimento automático.',
        'functions' => ['Conferir dados da empresa', 'Validar conexão WhatsApp', 'Preparar o assistente virtual', 'Realizar um teste final'],
        'steps' => [
            'Abra cada etapa e revise as informações solicitadas.',
            'Conclua primeiro os dados da empresa e a conexão WhatsApp.',
            'Configure o assistente e faça uma conversa de teste.',
            'Finalize o guia quando todos os itens essenciais estiverem prontos.',
        ],
        'tips' => ['Esse guia aparece somente quando o usuário possui permissão para gerenciar a configuração inicial.'],
        'url' => '/onboarding',
    ],
    [
        'slug' => 'conversas',
        'code' => 'CV',
        'title' => 'Conversas',
        'summary' => 'Caixa de atendimento para acompanhar mensagens do WhatsApp e trabalhar em conjunto com o assistente virtual.',
        'functions' => ['Responder mensagens', 'Assumir atendimento humano', 'Pausar ou reativar o assistente', 'Marcar como lida ou excluir conversas', 'Atualizar dados do contato'],
        'steps' => [
            'Escolha uma conversa na lista da esquerda.',
            'Leia o histórico e verifique se o assistente virtual está ativo.',
            'Use “Assumir atendimento” quando uma pessoa precisar continuar a conversa.',
            'Responda pelo campo inferior ou reative o assistente quando o atendimento humano terminar.',
            'Use o modo “Selecionar” para organizar várias conversas de uma vez.',
        ],
        'tips' => ['Excluir uma conversa remove o histórico do RS Connect, mas não apaga mensagens do WhatsApp.'],
        'url' => '/conversations',
    ],
    [
        'slug' => 'contatos',
        'code' => 'CT',
        'title' => 'Contatos',
        'summary' => 'Cadastro das pessoas atendidas, com telefone, dados básicos, etiquetas e observações.',
        'functions' => ['Pesquisar contatos', 'Atualizar dados', 'Organizar com etiquetas', 'Consultar histórico relacionado'],
        'steps' => [
            'Pesquise pelo nome ou telefone.',
            'Abra o contato desejado e confira os dados existentes.',
            'Complete informações úteis para a equipe e para o atendimento.',
            'Salve e use as etiquetas para facilitar filtros futuros.',
        ],
        'tips' => ['Mantenha telefone e nome atualizados para evitar contatos duplicados.'],
        'url' => '/contacts',
    ],
    [
        'slug' => 'crm',
        'code' => 'CR',
        'title' => 'CRM',
        'summary' => 'Organização de oportunidades em etapas comerciais para acompanhar cada lead até a conclusão.',
        'functions' => ['Visualizar oportunidades por etapa', 'Mover cards no funil', 'Registrar valor e responsável', 'Acompanhar o próximo passo'],
        'steps' => [
            'Localize a oportunidade no funil.',
            'Abra o card para conferir contato, valor e observações.',
            'Atualize o responsável e o próximo passo.',
            'Mova a oportunidade para a etapa correspondente ao andamento real.',
        ],
        'tips' => ['Atualize a etapa sempre que houver avanço ou encerramento da negociação.'],
        'url' => '/crm',
    ],
    [
        'slug' => 'atividades',
        'code' => 'AT',
        'title' => 'Atividades',
        'summary' => 'Lista de tarefas e retornos para a equipe não perder ligações, reuniões e próximos passos.',
        'functions' => ['Criar atividade', 'Definir prazo e responsável', 'Filtrar pendências', 'Concluir ou reagendar'],
        'steps' => [
            'Clique em criar nova atividade.',
            'Informe o tipo, a data, o responsável e a descrição.',
            'Use os filtros para acompanhar atrasadas e atividades do dia.',
            'Conclua a atividade após realizar o contato ou reagende quando necessário.',
        ],
        'tips' => ['Use descrições objetivas, como “Retornar orçamento” ou “Confirmar reunião”.'],
        'url' => '/tasks',
    ],
    [
        'slug' => 'agenda',
        'code' => 'AG',
        'title' => 'Agenda',
        'summary' => 'Gerenciamento de compromissos, pré-agendamentos e regras de disponibilidade em uma única área.',
        'functions' => ['Acompanhar compromissos', 'Aprovar ou remarcar pré-agendamentos', 'Buscar horários disponíveis', 'Configurar dias, duração e intervalo'],
        'steps' => [
            'Use a área “Compromissos” para acompanhar agendamentos e solicitações.',
            'Abra um pré-agendamento e busque disponibilidade antes de aprovar.',
            'Escolha um horário retornado e confirme a ação desejada.',
            'Na área “Disponibilidade”, defina dias, horários, duração e o modo de consulta da agenda.',
        ],
        'tips' => ['O botão Cancelar pode comunicar o cliente; o botão Excluir apenas remove o registro do sistema.'],
        'url' => '/calendar',
    ],
    [
        'slug' => 'whatsapp',
        'code' => 'WA',
        'title' => 'WhatsApp',
        'summary' => 'Área para visualizar a conexão preparada pelo Admin RS e conectar o número pelo QR Code.',
        'functions' => ['Ver status da conexão', 'Gerar QR Code', 'Reconectar o WhatsApp quando necessário'],
        'steps' => [
            'Abra a conexão liberada para a sua empresa.',
            'Se estiver desconectada, clique em gerar QR Code.',
            'No celular, abra WhatsApp > Aparelhos conectados > Conectar aparelho.',
            'Escaneie o código e aguarde o status mudar para conectado.',
        ],
        'tips' => ['O cadastro técnico da conexão é feito pelo Admin RS. O cliente apenas realiza a conexão do número.'],
        'url' => '/instances',
    ],
    [
        'slug' => 'assistentes',
        'code' => 'IA',
        'title' => 'Assistentes de IA',
        'summary' => 'Configuração do assistente virtual que responde contatos e segue as informações da sua empresa.',
        'functions' => ['Criar assistente', 'Editar instruções', 'Definir atendimento automático', 'Configurar transferência para uma pessoa'],
        'steps' => [
            'Clique em “Novo assistente” e selecione a conexão WhatsApp.',
            'Informe nome, área e objetivo do atendimento.',
            'Defina tom de voz, mensagem inicial e regras importantes.',
            'Revise as informações da empresa e crie o assistente.',
            'No card do assistente, ajuste disponibilidade, palavras de transferência e instruções quando necessário.',
        ],
        'tips' => ['Escreva regras simples e diretas. Não inclua senhas, chaves ou dados sigilosos nas instruções.'],
        'url' => '/agents',
    ],
    [
        'slug' => 'automacoes',
        'code' => 'AU',
        'title' => 'Automações',
        'summary' => 'Acompanhamento das respostas do assistente e das integrações acionadas durante o atendimento.',
        'functions' => ['Consultar respostas enviadas', 'Identificar falhas em linguagem simples', 'Ver integrações executadas', 'Acompanhar o resultado das automações'],
        'steps' => [
            'Use os filtros para localizar o período, o contato ou o tipo de evento.',
            'Observe o status de sucesso, atenção ou falha.',
            'Abra o registro para entender o que ocorreu e a ação recomendada.',
            'Procure o suporte ou o Admin RS quando a mensagem indicar uma configuração técnica.',
        ],
        'tips' => ['Os detalhes técnicos ficam restritos ao Admin RS; o cliente recebe uma explicação orientada à solução.'],
        'url' => '/automations',
    ],
    [
        'slug' => 'relatorios',
        'code' => 'RL',
        'title' => 'Relatórios',
        'summary' => 'Indicadores para acompanhar conversas, contatos, oportunidades e desempenho da operação.',
        'functions' => ['Filtrar períodos', 'Acompanhar volumes e resultados', 'Comparar indicadores', 'Identificar pontos de atenção'],
        'steps' => [
            'Escolha o período que deseja analisar.',
            'Aplique os filtros disponíveis para a sua equipe.',
            'Leia os indicadores e gráficos apresentados.',
            'Use os resultados para ajustar atendimento, agenda e processo comercial.',
        ],
        'tips' => ['Compare períodos equivalentes para obter uma leitura mais justa da evolução.'],
        'url' => '/reports',
    ],
    [
        'slug' => 'minha-empresa',
        'code' => 'ME',
        'title' => 'Minha empresa',
        'summary' => 'Dados comerciais e informações que identificam a empresa e ajudam o assistente a responder com contexto.',
        'functions' => ['Atualizar dados cadastrais', 'Informar contatos e endereço', 'Descrever serviços e diferenciais', 'Configurar informações usadas no atendimento'],
        'steps' => [
            'Revise nome, documento, segmento e contato comercial.',
            'Preencha site, redes sociais e endereço quando aplicável.',
            'Descreva a empresa, seus serviços e diferenciais.',
            'Informe horário e observações importantes para o atendimento.',
            'Clique em salvar alterações.',
        ],
        'tips' => ['Quanto mais claras forem essas informações, melhor será a base usada pelos assistentes.'],
        'url' => '/company-settings',
    ],
    [
        'slug' => 'usuarios',
        'code' => 'US',
        'title' => 'Usuários e permissões',
        'summary' => 'Gerenciamento das pessoas que acessam o sistema e do que cada uma pode visualizar ou alterar.',
        'functions' => ['Cadastrar usuário', 'Definir perfil', 'Ativar ou desativar acesso', 'Controlar permissões'],
        'steps' => [
            'Acesse Usuários e crie ou edite uma pessoa da equipe.',
            'Informe nome, e-mail e perfil de acesso.',
            'Revise as permissões antes de liberar o acesso.',
            'Desative usuários que não fazem mais parte da operação.',
        ],
        'tips' => ['Conceda somente as permissões necessárias para a função de cada pessoa.'],
        'url' => '/users',
    ],
    [
        'slug' => 'notificacoes',
        'code' => 'NT',
        'title' => 'Notificações',
        'summary' => 'Central de avisos e preferências para escolher quais acontecimentos devem aparecer no sininho.',
        'functions' => ['Ativar alertas de mensagens', 'Receber avisos do assistente', 'Acompanhar falhas de integrações', 'Receber avisos de agenda e conta'],
        'steps' => [
            'Abra as preferências de notificação.',
            'Ative somente os tipos de aviso importantes para a sua equipe.',
            'Salve as alterações.',
            'Use o sininho para acompanhar avisos novos e abrir o item relacionado.',
        ],
        'tips' => ['Desativar uma notificação não interrompe o módulo; apenas remove aquele tipo de aviso do sininho.'],
        'url' => '/notifications',
    ],
    [
        'slug' => 'privacidade',
        'code' => 'LG',
        'title' => 'Privacidade e LGPD',
        'summary' => 'Recursos para acompanhar termos, consentimentos e solicitações relacionadas aos dados pessoais.',
        'functions' => ['Consultar termos', 'Acompanhar consentimentos', 'Registrar solicitações do titular', 'Exportar ou anonimizar dados quando permitido'],
        'steps' => [
            'Confira os termos e políticas disponíveis.',
            'Localize o contato relacionado à solicitação.',
            'Escolha a ação adequada conforme a autorização da empresa.',
            'Registre a conclusão para manter o histórico da solicitação.',
        ],
        'tips' => ['Em caso de dúvida jurídica, valide o procedimento com o responsável pela privacidade da empresa.'],
        'url' => '/privacy',
    ],
    [
        'slug' => 'assinatura',
        'code' => 'AS',
        'title' => 'Minha assinatura',
        'summary' => 'Consulta do plano contratado, situação da assinatura e cobranças vinculadas à conta.',
        'functions' => ['Ver plano atual', 'Consultar situação da assinatura', 'Acompanhar faturas e vencimentos'],
        'steps' => [
            'Confira o plano e a situação exibidos no topo.',
            'Revise os limites e recursos disponíveis.',
            'Consulte as cobranças e datas de vencimento.',
            'Entre em contato com o Admin RS quando precisar alterar o plano ou corrigir uma cobrança.',
        ],
        'tips' => ['Avisos financeiros também podem ser habilitados na Central de notificações.'],
        'url' => '/subscription',
    ],
];

$adminManuals = [
    [
        'slug' => 'admin-empresas',
        'code' => 'EP',
        'title' => 'Empresas',
        'summary' => 'Cadastro e administração das empresas clientes atendidas pela plataforma.',
        'functions' => ['Criar empresa', 'Selecionar empresa ativa', 'Configurar módulos e plano', 'Acompanhar situação do cliente'],
        'steps' => ['Cadastre os dados principais.', 'Crie o usuário responsável.', 'Prepare conexão, credencial e módulos.', 'Valide o acesso pelo perfil do cliente.'],
        'tips' => ['Revise sempre a empresa selecionada antes de alterar integrações ou dados técnicos.'],
        'url' => '/companies',
    ],
    [
        'slug' => 'admin-integracoes',
        'code' => 'IN',
        'title' => 'Integrações n8n',
        'summary' => 'Configuração de fluxos por empresa, callbacks e rotinas externas conectadas ao RS Connect.',
        'functions' => ['Cadastrar URL e token', 'Testar fluxo', 'Baixar templates', 'Acompanhar callbacks'],
        'steps' => ['Selecione a empresa.', 'Cadastre a URL de produção e o token.', 'Ative o workflow no n8n.', 'Execute o teste e confira os logs.'],
        'tips' => ['Use uma URL e credencial específicas para cada empresa sempre que houver dados isolados.'],
        'url' => '/n8n-flows',
    ],
    [
        'slug' => 'admin-monitoramento',
        'code' => 'MO',
        'title' => 'Monitoramento',
        'summary' => 'Saúde dos serviços, alertas, backups e orientações de recuperação da plataforma.',
        'functions' => ['Executar verificações', 'Acompanhar alertas', 'Registrar backups', 'Consultar incidentes'],
        'steps' => ['Clique em verificar agora.', 'Revise falhas e atenções.', 'Execute a ação recomendada.', 'Registre a solução ou o backup quando aplicável.'],
        'tips' => ['Alertas técnicos e diagnósticos são destinados à equipe RS.'],
        'url' => '/operations',
    ],
    [
        'slug' => 'admin-implantacao',
        'code' => 'IM',
        'title' => 'Implantação',
        'summary' => 'Checklist interno para preparar um novo cliente e confirmar os itens essenciais antes da entrega.',
        'functions' => ['Acompanhar etapas', 'Registrar responsáveis', 'Validar configurações', 'Concluir implantação'],
        'steps' => ['Selecione a empresa.', 'Revise cada etapa técnica e comercial.', 'Teste WhatsApp, IA e permissões.', 'Conclua somente após validar o acesso do cliente.'],
        'tips' => ['Use esse checklist como processo interno; o cliente utiliza “Primeiros passos”.'],
        'url' => '/implementation',
    ],
];

$manuals = !empty($is_super_admin) ? array_merge($clientManuals, $adminManuals) : $clientManuals;

$troubleshooting = [
    ['title' => 'Botão salva, mas a tela não muda', 'fix' => 'Conferir resposta da requisição, mensagem de sucesso, cache do navegador e dados gravados no banco.'],
    ['title' => 'Webhook retornou erro', 'fix' => 'Validar URL, token, workflow ativo e logs do serviço responsável pela entrega.'],
    ['title' => 'Assistente não respondeu', 'fix' => 'Conferir credencial, agente ativo, mensagem recebida, saldo/limite e logs de automação.'],
    ['title' => 'Backup não validou', 'fix' => 'Conferir rotina, arquivo gerado, callback e registro no monitoramento.'],
];
?>

<section class="hero-card docs-hero docs-manual-hero">
    <div>
        <span class="eyebrow"><?= !empty($is_super_admin) ? 'Manual da plataforma' : 'Manual do cliente' ?></span>
        <h2>Aprenda a usar cada módulo do RS Connect.</h2>
        <p><?= !empty($is_super_admin)
            ? 'Consulte os módulos do cliente e as áreas administrativas. Os guias técnicos adicionais permanecem disponíveis somente para a equipe RS.'
            : 'Escolha um módulo para ver o que ele faz, suas principais funções e um passo a passo simples de utilização.' ?></p>
    </div>
    <div class="docs-hero-summary" aria-label="Resumo da central de ajuda">
        <strong><?= count($manuals) ?></strong>
        <span>guias disponíveis</span>
    </div>
</section>

<section class="docs-manual-section" style="margin-top:16px">
    <div class="section-heading docs-manual-heading">
        <div>
            <span class="eyebrow">Módulos do sistema</span>
            <h2>Escolha o que deseja aprender</h2>
            <p class="muted-text">O manual abre na lateral sem tirar você desta página.</p>
        </div>
    </div>

    <div class="docs-grid docs-manual-grid">
        <?php foreach ($manuals as $manual): ?>
            <article class="card docs-card docs-module-card">
                <div class="docs-card-head">
                    <span class="docs-icon" aria-hidden="true"><?= View::e($manual['code']) ?></span>
                    <div>
                        <h3><?= View::e($manual['title']) ?></h3>
                        <p><?= View::e($manual['summary']) ?></p>
                    </div>
                </div>
                <div class="docs-tags" aria-label="Principais funções">
                    <?php foreach (array_slice($manual['functions'], 0, 3) as $item): ?>
                        <span><?= View::e($item) ?></span>
                    <?php endforeach; ?>
                </div>
                <button class="btn btn-small btn-outline docs-open-manual" type="button" data-toggle-panel="docs-manual-<?= View::e($manual['slug']) ?>">
                    Ver manual do módulo
                </button>
            </article>
        <?php endforeach; ?>
    </div>
</section>

<?php foreach ($manuals as $manual): ?>
    <aside class="conversation-details conversation-drawer docs-manual-drawer" id="docs-manual-<?= View::e($manual['slug']) ?>" aria-label="Manual: <?= View::e($manual['title']) ?>" aria-modal="true" role="dialog">
        <div class="conversation-drawer-header docs-manual-drawer-header">
            <div>
                <span class="eyebrow">Manual do módulo</span>
                <h2><?= View::e($manual['title']) ?></h2>
                <p><?= View::e($manual['summary']) ?></p>
            </div>
            <button class="icon-button drawer-close" type="button" data-close-panel="docs-manual-<?= View::e($manual['slug']) ?>" aria-label="Fechar manual">×</button>
        </div>
        <div class="conversation-drawer-body docs-manual-drawer-body">
            <section class="drawer-section docs-manual-block">
                <div class="drawer-section-title">
                    <div><span class="eyebrow">Principais funções</span><h3>O que você pode fazer</h3></div>
                </div>
                <ul class="docs-function-list">
                    <?php foreach ($manual['functions'] as $item): ?><li><?= View::e($item) ?></li><?php endforeach; ?>
                </ul>
            </section>

            <section class="drawer-section docs-manual-block">
                <div class="drawer-section-title">
                    <div><span class="eyebrow">Passo a passo</span><h3>Como usar</h3></div>
                </div>
                <ol class="docs-step-list">
                    <?php foreach ($manual['steps'] as $index => $step): ?>
                        <li><span><?= (int) $index + 1 ?></span><p><?= View::e($step) ?></p></li>
                    <?php endforeach; ?>
                </ol>
            </section>

            <?php if (!empty($manual['tips'])): ?>
                <section class="docs-manual-note">
                    <strong>Importante</strong>
                    <?php foreach ($manual['tips'] as $tip): ?><p><?= View::e($tip) ?></p><?php endforeach; ?>
                </section>
            <?php endif; ?>
        </div>
        <div class="drawer-savebar docs-manual-actions">
            <button class="btn btn-quiet" type="button" data-close-panel="docs-manual-<?= View::e($manual['slug']) ?>">Fechar</button>
            <a class="btn btn-primary" href="<?= View::e(Router::url($manual['url'])) ?>">Acessar módulo</a>
        </div>
    </aside>
<?php endforeach; ?>

<?php if (!empty($is_super_admin)): ?>
    <div class="operations-grid docs-admin-support" style="margin-top:16px">
        <section class="card">
            <div class="section-heading"><div><span class="eyebrow">Uso interno RS</span><h2>Ordem recomendada para operação</h2></div></div>
            <div class="docs-timeline">
                <div><strong>1. Implantação</strong><span>Empresa, usuário, WhatsApp, IA e checklist interno.</span></div>
                <div><strong>2. Onboarding do cliente</strong><span>Cliente conclui primeiros passos e aceita termos.</span></div>
                <div><strong>3. Atendimento</strong><span>Conversas, CRM, agenda e automações.</span></div>
                <div><strong>4. Operação</strong><span>Monitoramento, backup, cobrança e revisão recorrente.</span></div>
            </div>
        </section>
        <section class="card">
            <div class="section-heading"><div><span class="eyebrow">Uso interno RS</span><h2>Diagnóstico rápido</h2></div></div>
            <div class="security-list">
                <?php foreach ($troubleshooting as $item): ?>
                    <div class="security-row">
                        <div><strong><?= View::e($item['title']) ?></strong><small><?= View::e($item['fix']) ?></small></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    </div>
<?php endif; ?>
