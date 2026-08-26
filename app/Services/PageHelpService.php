<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Conteúdo de ajuda curto e contextual para as telas de uso diário.
 *
 * Os nomes técnicos continuam disponíveis nos manuais e nas áreas avançadas,
 * mas a primeira explicação deve ser compreensível por uma pessoa iniciante.
 */
final class PageHelpService
{
    /** @return array<string, mixed> */
    public function forPath(string $path, bool $isSuperAdmin = false): array
    {
        $path = '/' . trim((string) parse_url($path, PHP_URL_PATH), '/');
        if ($path === '//') {
            $path = '/';
        }

        foreach ($this->contexts($isSuperAdmin) as $prefix => $context) {
            if ($prefix === '/' ? $path === '/' : str_starts_with($path, $prefix)) {
                return $context + ['path' => $path];
            }
        }

        return [
            'title' => 'Ajuda desta página',
            'summary' => 'Use esta área para consultar e atualizar as informações mostradas na tela.',
            'steps' => [
                'Leia o título e a explicação do bloco antes de alterar os dados.',
                'Preencha somente as informações que você conhece.',
                'Revise os dados e clique no botão de salvar ou aplicar.',
            ],
            'tips' => [
                'Campos com explicação abaixo mostram como a informação será usada.',
                'Configurações técnicas ficam em áreas avançadas e podem ser revisadas pela equipe RS.',
            ],
            'terms' => [],
            'manual_url' => '/ajuda',
            'primary_url' => null,
            'primary_label' => null,
            'path' => $path,
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function contexts(bool $isSuperAdmin): array
    {
        $contexts = [
            '/onboarding' => [
                'title' => 'Prepare sua empresa passo a passo',
                'summary' => 'Conclua as etapas na ordem para deixar o WhatsApp, o assistente e as regras de atendimento prontos.',
                'steps' => [
                    'Abra a primeira etapa que ainda não foi concluída.',
                    'Confira as informações e salve antes de seguir.',
                    'Faça uma conversa real no final para confirmar que tudo funciona.',
                ],
                'tips' => ['Uma etapa bloqueada será liberada quando a anterior estiver pronta.'],
                'terms' => ['Assistente virtual' => 'A inteligência artificial que responde conforme as regras da empresa.'],
                'manual_url' => '/ajuda#primeiros-passos',
                'primary_url' => '/onboarding',
                'primary_label' => 'Continuar configuração',
            ],
            '/conversations' => [
                'title' => 'Atenda as conversas do WhatsApp',
                'summary' => 'Leia as mensagens, assuma o atendimento quando necessário e acompanhe quem está responsável pela conversa.',
                'steps' => [
                    'Escolha uma conversa na lista.',
                    'Leia as mensagens e verifique se o assistente está ativo ou pausado.',
                    'Clique em “Assumir atendimento” para responder como pessoa.',
                    'Ao terminar, libere para a equipe ou reative o assistente de forma explícita.',
                ],
                'tips' => [
                    'Enquanto uma pessoa estiver responsável, outra não poderá responder ao mesmo tempo.',
                    'Mensagens fora do horário permanecem guardadas até a equipe assumir ou o atendimento voltar.',
                ],
                'terms' => ['Fila' => 'Conversas que aguardam atendimento.', 'Assistente pausado' => 'A IA não responderá até ser reativada.'],
                'manual_url' => '/ajuda#conversas',
                'primary_url' => null,
                'primary_label' => null,
            ],
            '/contacts' => [
                'title' => 'Organize as pessoas atendidas',
                'summary' => 'Pesquise contatos, complete informações úteis e use grupos ou etiquetas para facilitar o atendimento.',
                'steps' => ['Pesquise pelo nome ou telefone.', 'Abra ou crie o contato.', 'Revise os dados e salve.'],
                'tips' => ['Evite cadastrar o mesmo telefone mais de uma vez.'],
                'terms' => ['Grupo de atendimento' => 'Regra usada para encaminhar o contato à equipe ou ao assistente correto.'],
                'manual_url' => '/ajuda#contatos',
                'primary_url' => null,
                'primary_label' => null,
            ],
            '/crm' => [
                'title' => 'Acompanhe oportunidades comerciais',
                'summary' => 'Organize os contatos por etapa e registre a próxima atividade para não perder uma oportunidade.',
                'steps' => ['Use a busca ou os filtros.', 'Abra uma oportunidade.', 'Atualize a etapa, o responsável e a próxima atividade.'],
                'tips' => ['Mova a oportunidade somente quando a etapa realmente mudar.'],
                'terms' => ['Etapa' => 'Momento atual da negociação.', 'Oportunidade' => 'Pessoa ou empresa com possibilidade de contratar.'],
                'manual_url' => '/ajuda#crm',
                'primary_url' => null,
                'primary_label' => null,
            ],
            '/tasks' => [
                'title' => 'Organize as próximas atividades',
                'summary' => 'Registre o que precisa ser feito, quem é responsável e até quando a atividade deve ser concluída.',
                'steps' => ['Crie ou abra uma atividade.', 'Defina responsável e prazo.', 'Marque como concluída quando terminar.'],
                'tips' => ['Use descrições curtas e objetivas.'],
                'terms' => [],
                'manual_url' => '/ajuda#atividades',
                'primary_url' => null,
                'primary_label' => null,
            ],
            '/calendar' => [
                'title' => 'Acompanhe horários e compromissos',
                'summary' => 'Consulte a agenda, registre compromissos e verifique horários disponíveis.',
                'steps' => ['Escolha o período.', 'Abra ou crie o compromisso.', 'Confirme data, horário e responsável antes de salvar.'],
                'tips' => ['Verifique possíveis conflitos antes de confirmar com o cliente.'],
                'terms' => ['Disponibilidade' => 'Horários livres que podem ser oferecidos ao cliente.'],
                'manual_url' => '/ajuda#agenda',
                'primary_url' => null,
                'primary_label' => null,
            ],
            '/instances' => [
                'title' => 'Gerencie os números do WhatsApp',
                'summary' => 'Crie, conecte e acompanhe os números usados pela empresa sem sair do RS Connect.',
                'steps' => ['Crie uma nova conexão.', 'Leia o QR Code com o WhatsApp.', 'Confira se aparece como conectada.', 'Vincule o assistente e faça uma mensagem de teste.'],
                'tips' => ['Não exclua uma conexão em uso sem escolher para onde os dados serão transferidos.'],
                'terms' => ['Conexão do WhatsApp' => 'O número conectado ao RS Connect.', 'Eventos recebidos' => 'Atualizações que o WhatsApp envia ao sistema.'],
                'manual_url' => '/ajuda#whatsapp',
                'primary_url' => '/instances',
                'primary_label' => 'Ver conexões',
            ],
            '/agents' => [
                'title' => 'Configure o assistente virtual',
                'summary' => 'Defina como o assistente deve responder, quais informações pode usar e em quais números deve atuar.',
                'steps' => ['Escolha ou crie o assistente.', 'Escreva instruções claras.', 'Vincule o número do WhatsApp.', 'Faça uma conversa de teste.'],
                'tips' => ['Escreva regras como se estivesse orientando uma pessoa nova da equipe.'],
                'terms' => ['Modelo de IA' => 'Tecnologia usada para gerar a resposta.', 'Memória da conversa' => 'Resumo que ajuda o assistente a lembrar o contexto sem repetir todo o histórico.'],
                'manual_url' => '/ajuda#assistentes',
                'primary_url' => '/agents',
                'primary_label' => 'Ver assistentes',
            ],
            '/ai-commercial-attention' => [
                'title' => 'Veja quais clientes precisam de atenção',
                'summary' => 'A lista explica o motivo da atenção e sugere uma próxima ação comercial ou de redução de custo.',
                'steps' => ['Abra um cliente da lista.', 'Leia os motivos apresentados.', 'Registre a situação, a próxima revisão e o que precisa ser feito.'],
                'tips' => ['Salvar o acompanhamento não altera preço, plano ou limite automaticamente.'],
                'terms' => ['Percentual que sobra' => 'Parte da receita que permanece após os custos informados.', 'Custo previsto' => 'Estimativa até o fim do período atual.'],
                'manual_url' => '/ajuda',
                'primary_url' => null,
                'primary_label' => null,
            ],
            '/openai-usage' => [
                'title' => 'Entenda o uso e o custo da IA',
                'summary' => 'Compare o consumo oficial com o uso identificado por empresa e assistente.',
                'steps' => ['Escolha o período.', 'Confira o custo oficial.', 'Use os rankings para localizar quem mais consumiu.', 'Revise limites e alertas por empresa.'],
                'tips' => ['Consumo oficial inclui tudo que passou pelo projeto da OpenAI; o custo por empresa mostra somente o que o RS Connect conseguiu identificar.'],
                'terms' => ['Unidades de uso' => 'Quantidade de texto processado e gerado pela IA.', 'Chamadas evitadas' => 'Respostas locais ou reaproveitadas que não precisaram consultar a IA.'],
                'manual_url' => '/ajuda',
                'primary_url' => null,
                'primary_label' => null,
            ],
            '/ai-profitability' => [
                'title' => 'Acompanhe os resultados por cliente',
                'summary' => 'Compare valor mensal, custo previsto e percentual que sobra, além de simular planos e mensalidades.',
                'steps' => ['Escolha uma empresa.', 'Confira a origem dos valores.', 'Leia o histórico mensal.', 'Use a simulação antes de decidir uma alteração comercial.'],
                'tips' => ['Os valores representam os custos informados no sistema e não substituem a contabilidade.'],
                'terms' => ['Receita mensal' => 'Valor mensal usado como referência.', 'Percentual que sobra' => 'Parte da receita que permanece após os custos conhecidos.'],
                'manual_url' => '/ajuda',
                'primary_url' => null,
                'primary_label' => null,
            ],
            '/reports' => [
                'title' => 'Acompanhe os resultados da operação',
                'summary' => 'Use períodos e filtros equivalentes para comparar atendimento, conversas e resultados.',
                'steps' => ['Escolha o período.', 'Aplique os filtros.', 'Leia os indicadores e abra os detalhes quando necessário.'],
                'tips' => ['Compare períodos com a mesma quantidade de dias.'],
                'terms' => [],
                'manual_url' => '/ajuda#relatorios',
                'primary_url' => null,
                'primary_label' => null,
            ],
            '/billing' => [
                'title' => 'Acompanhe cobranças e pagamentos',
                'summary' => 'Consulte valores, vencimentos, situação e links de pagamento.',
                'steps' => ['Localize a cobrança.', 'Confira valor e vencimento.', 'Gere o link ou atualize a situação quando necessário.'],
                'tips' => ['Só marque como paga após confirmar o recebimento.'],
                'terms' => ['Situação da cobrança' => 'Indica se está aberta, paga, vencida ou cancelada.'],
                'manual_url' => '/ajuda#assinatura',
                'primary_url' => null,
                'primary_label' => null,
            ],
            '/payment-gateways' => [
                'title' => 'Configure os meios de pagamento',
                'summary' => 'Cadastre a empresa que processará o pagamento e guarde as chaves de acesso com segurança.',
                'steps' => ['Escolha o serviço.', 'Informe o ambiente.', 'Cole as chaves solicitadas.', 'Salve e faça um teste.'],
                'tips' => ['Use o ambiente de testes antes de liberar cobranças reais.'],
                'terms' => ['Ambiente de testes' => 'Permite simular pagamentos sem movimentar dinheiro.', 'Chave de acesso' => 'Código secreto que autoriza a conexão com o serviço.'],
                'manual_url' => '/ajuda',
                'primary_url' => null,
                'primary_label' => null,
            ],
            '/users' => [
                'title' => 'Gerencie os acessos da equipe',
                'summary' => 'Crie usuários e libere somente as funções necessárias para cada pessoa.',
                'steps' => ['Crie ou abra o usuário.', 'Escolha a empresa e o perfil.', 'Revise as permissões.', 'Salve e teste o acesso.'],
                'tips' => ['Desative o acesso de quem não faz mais parte da equipe.'],
                'terms' => ['Perfil' => 'Conjunto de permissões adequado à função da pessoa.'],
                'manual_url' => '/ajuda#usuarios',
                'primary_url' => null,
                'primary_label' => null,
            ],
            '/companies' => [
                'title' => 'Gerencie as empresas clientes',
                'summary' => 'Cadastre clientes, revise plano, módulos, responsáveis e situação da implantação.',
                'steps' => ['Localize ou crie a empresa.', 'Revise o plano e os módulos.', 'Crie o administrador do cliente.', 'Faça o teste de primeiro acesso.'],
                'tips' => ['Confira a empresa selecionada antes de alterar qualquer conexão ou chave.'],
                'terms' => [],
                'manual_url' => '/ajuda',
                'primary_url' => null,
                'primary_label' => null,
            ],
            '/operations' => [
                'title' => 'Acompanhe a saúde do sistema',
                'summary' => 'Veja avisos, verificações e orientações para corrigir situações que podem afetar o atendimento.',
                'steps' => ['Execute a verificação.', 'Abra os itens que precisam de atenção.', 'Siga a ação recomendada.', 'Registre a solução.'],
                'tips' => ['Detalhes técnicos devem ser usados pela equipe responsável pelo suporte.'],
                'terms' => ['Verificação' => 'Teste automático para confirmar se um serviço está funcionando.'],
                'manual_url' => '/ajuda',
                'primary_url' => null,
                'primary_label' => null,
            ],
            '/' => [
                'title' => 'Comece pela visão geral',
                'summary' => $isSuperAdmin
                    ? 'Veja o que precisa de atenção e use os atalhos para abrir a empresa ou o módulo relacionado.'
                    : 'Veja conversas, atividades e avisos importantes antes de começar o atendimento.',
                'steps' => ['Confira os avisos do topo.', 'Abra os itens que precisam de atenção.', 'Use os atalhos para continuar o trabalho.'],
                'tips' => ['A busca no topo localiza páginas e funções. Pressione Ctrl + K para abrir.'],
                'terms' => [],
                'manual_url' => '/ajuda',
                'primary_url' => $isSuperAdmin ? '/companies' : '/onboarding',
                'primary_label' => $isSuperAdmin ? 'Ver empresas' : 'Abrir primeiros passos',
            ],
        ];

        // Rotas mais específicas precisam ser verificadas antes das rotas genéricas.
        uksort($contexts, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        return $contexts;
    }
}
