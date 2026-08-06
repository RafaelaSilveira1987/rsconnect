<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;

final class ExecutiveReportPdfService
{
    private const MARGIN = 42.0;
    private const CONTENT_WIDTH = 511.0;

    /**
     * @param array<string,mixed> $filters
     * @param array<string,mixed> $data
     * @param array<string,mixed> $identity
     * @param list<string> $sections
     * @return array{bytes:string,summary:array<string,mixed>}
     */
    public function generate(string $scope, array $filters, array $data, array $identity, array $sections): array
    {
        $scope = $scope === 'admin' ? 'admin' : 'tenant';
        $sections = $sections !== [] ? array_values(array_unique($sections)) : $this->defaultSections($scope);
        // A estrutura do documento segue a identidade da plataforma. A empresa
        // continua identificada no cabeçalho, mas o relatório mantém a mesma
        // linguagem visual da RS Connect em todos os tenants.
        $primary = '#2F80FF';
        $secondary = '#7B3FF2';
        $accent = '#14B8A6';
        $name = trim((string) ($identity['name'] ?? ($scope === 'admin' ? 'RS Connect' : 'Empresa')));
        $title = trim((string) ($identity['report_title'] ?? 'Relatório executivo'));
        $periodLabel = $this->dateBr((string) ($filters['start'] ?? '')) . ' a ' . $this->dateBr((string) ($filters['end'] ?? ''));

        $pdf = new SimplePdfDocument();
        $pdf->addPage();
        $this->header($pdf, $title, $name, $periodLabel, $primary, $secondary);
        $y = 124.0;

        $metrics = is_array($data['metrics'] ?? null) ? $data['metrics'] : [];
        $comparisons = is_array($data['comparisons'] ?? null) ? $data['comparisons'] : [];
        $kpis = $scope === 'admin'
            ? $this->adminKpis($metrics, $comparisons)
            : $this->tenantKpis($metrics, $comparisons);
        $y = $this->kpiGrid($pdf, $y, $kpis, $primary, $accent);

        if ($scope === 'admin') {
            $this->renderAdminSections($pdf, $y, $data, $sections, $primary, $secondary, $accent, $name, $periodLabel);
        } else {
            $this->renderTenantSections($pdf, $y, $data, $sections, $primary, $secondary, $accent, $name, $periodLabel);
        }

        $warnings = is_array($data['warnings'] ?? null) ? array_values(array_filter($data['warnings'], 'is_string')) : [];
        if ($warnings !== []) {
            $this->ensureSpace($pdf, $y, 95, $name, $periodLabel, $primary);
            $y = $this->sectionTitle($pdf, $y, 'Observações da geração', 'Indicadores que merecem revisão técnica.', $primary);
            foreach (array_slice($warnings, 0, 5) as $warning) {
                $pdf->text(self::MARGIN + 8, $y, '• ' . $warning, 8.5, false, '#8a5b14');
                $y += 14;
            }
        }

        $summary = [
            'scope' => $scope,
            'identity' => $name,
            'period_start' => (string) ($filters['start'] ?? ''),
            'period_end' => (string) ($filters['end'] ?? ''),
            'sections' => $sections,
            'metrics' => $this->summaryMetrics($scope, $metrics),
            'warnings' => count($warnings),
            'pages' => $pdf->pageCount(),
            'generated_at' => (new DateTimeImmutable('now'))->format(DATE_ATOM),
        ];

        $bytes = $pdf->output();
        $summary['pages'] = $pdf->pageCount();
        $summary['size_bytes'] = strlen($bytes);
        return ['bytes' => $bytes, 'summary' => $summary];
    }

    /** @return list<string> */
    private function defaultSections(string $scope): array
    {
        return $scope === 'admin'
            ? ['overview', 'companies', 'usage', 'health', 'automation', 'agenda', 'commercial']
            : ['overview', 'conversations', 'team', 'agenda', 'ai', 'attention'];
    }

    private function header(
        SimplePdfDocument $pdf,
        string $title,
        string $name,
        string $period,
        string $primary,
        string $secondary
    ): void {
        $pdf->rect(0, 0, 370, 6, $primary, null);
        $pdf->rect(370, 0, SimplePdfDocument::PAGE_WIDTH - 370, 6, $secondary, null);
        $pdf->jpeg($this->logoPath(), self::MARGIN, 13, 68, 60);
        $pdf->rect(self::MARGIN + 84, 25, 118, 20, '#F2EDFF', null);
        $pdf->text(self::MARGIN + 93, 30, 'RELATÓRIO EXECUTIVO', 7.1, true, $secondary);
        $pdf->text(self::MARGIN + 84, 55, mb_strtoupper($name), 8.3, true, '#5B6578');
        $pdf->text(350, 24, 'PERÍODO ANALISADO', 6.8, true, '#7A8496');
        $pdf->text(350, 37, $period, 9.4, true, '#253047');
        $pdf->text(350, 58, 'GERADO EM', 6.8, true, '#7A8496');
        $pdf->text(350, 71, date('d/m/Y H:i'), 8.2, false, '#4F5B70');
        $pdf->line(self::MARGIN, 105, self::MARGIN + self::CONTENT_WIDTH, 105, '#E3E8F2', 0.7);
    }

    private function continuationHeader(SimplePdfDocument $pdf, string $name, string $period, string $primary): void
    {
        $pdf->rect(0, 0, 370, 5, '#2F80FF', null);
        $pdf->rect(370, 0, SimplePdfDocument::PAGE_WIDTH - 370, 5, '#7B3FF2', null);
        $pdf->jpeg($this->logoPath(), self::MARGIN, 7, 39, 34);
        $pdf->text(94, 18, 'RELATÓRIO EXECUTIVO', 7.4, true, '#7B3FF2');
        $pdf->text(225, 18, mb_strtoupper($name), 7.4, true, '#4F5B70');
        $pdf->text(395, 18, $period, 7.4, false, '#7A8496');
        $pdf->line(self::MARGIN, 46, self::MARGIN + self::CONTENT_WIDTH, 46, '#E3E8F2', 0.7);
    }

    /**
     * @param list<array{label:string,value:string,detail:string,tone:string}> $kpis
     */
    private function kpiGrid(SimplePdfDocument $pdf, float $y, array $kpis, string $primary, string $accent): float
    {
        $columns = 4;
        $gap = 8.0;
        $width = (self::CONTENT_WIDTH - (($columns - 1) * $gap)) / $columns;
        $height = 76.0;
        foreach ($kpis as $index => $kpi) {
            $col = $index % $columns;
            $row = intdiv($index, $columns);
            $x = self::MARGIN + ($col * ($width + $gap));
            $top = $y + ($row * ($height + $gap));
            $tone = $kpi['tone'] === 'accent' ? '#7B3FF2' : ($kpi['tone'] === 'attention' ? '#E35B75' : $primary);
            $fill = $kpi['tone'] === 'accent' ? '#FBF9FF' : ($kpi['tone'] === 'attention' ? '#FFF9FA' : '#F8FBFF');
            $pdf->rect($x, $top, $width, $height, $fill, '#E3E8F2', 0.65);
            $pdf->rect($x, $top, $width, 3, $tone, null);
            $pdf->paragraph($x + 10, $top + 11, $width - 20, mb_strtoupper($kpi['label']), 6.6, 8.0, true, '#6A7487', 2);
            $valueSize = mb_strlen($kpi['value']) > 10 ? 12.5 : 17.5;
            $pdf->text($x + 10, $top + 32, $kpi['value'], $valueSize, true, '#172033');
            $pdf->paragraph($x + 10, $top + 54, $width - 20, $kpi['detail'], 6.8, 8.4, false, '#687589', 2);
        }
        return $y + (ceil(count($kpis) / $columns) * ($height + $gap)) + 8;
    }

    /** @return list<array{label:string,value:string,detail:string,tone:string}> */
    private function adminKpis(array $m, array $c): array
    {
        $measuredResponses = (int) ($m['first_responses_measured'] ?? $m['first_responses'] ?? 0);
        return [
            ['label' => 'Empresas ativas', 'value' => $this->number($m['active_companies'] ?? 0), 'detail' => $this->trend($c['new_companies'] ?? null, 'novas no período'), 'tone' => 'primary'],
            ['label' => 'Conversas iniciadas', 'value' => $this->number($m['conversations_started'] ?? 0), 'detail' => $this->trend($c['conversations_started'] ?? null, 'comparação anterior'), 'tone' => 'primary'],
            ['label' => 'Atendimentos humanos', 'value' => $this->number($m['human_conversations'] ?? 0), 'detail' => $this->number($m['human_messages'] ?? 0) . ' respostas da equipe', 'tone' => 'accent'],
            ['label' => '1ª resposta', 'value' => $measuredResponses > 0 ? $this->duration($m['avg_first_response_seconds'] ?? 0) : 'Não mensurado', 'detail' => $measuredResponses > 0 ? $this->plural($measuredResponses, 'resposta medida', 'respostas medidas') : 'Nenhum ciclo com tempo disponível', 'tone' => 'primary'],
            ['label' => 'Agendamentos', 'value' => $this->number($m['appointments'] ?? 0), 'detail' => $this->percent($m['agenda_conversion'] ?? 0) . ' confirmados/concluídos', 'tone' => 'accent'],
            ['label' => 'Comparecimento', 'value' => $this->percent($m['attendance_rate'] ?? 0), 'detail' => $this->completedAppointmentsDetail((int) ($m['appointments_completed'] ?? 0)), 'tone' => 'accent'],
            ['label' => 'Uso da IA', 'value' => $this->number($m['ai_replies'] ?? 0), 'detail' => $this->percent($m['ai_share'] ?? 0) . ' das respostas', 'tone' => 'primary'],
            ['label' => 'Incidentes operacionais', 'value' => $this->number($m['open_operational_incidents'] ?? 0), 'detail' => $this->automationFailuresDetail((int) ($m['automation_failures'] ?? 0)), 'tone' => 'primary'],
        ];
    }

    /** @return list<array{label:string,value:string,detail:string,tone:string}> */
    private function tenantKpis(array $m, array $c): array
    {
        $newConversations = (int) ($m['conversations'] ?? 0);
        $measuredResponses = (int) ($m['first_responses_measured'] ?? $m['first_responses'] ?? 0);
        $attention = (int) ($m['attention_conversations'] ?? 0);
        $attentionHuman = (int) ($m['attention_human_conversations'] ?? 0);
        $unread = (int) ($m['unread'] ?? 0);
        return [
            ['label' => 'Conversas atendidas', 'value' => $this->number($m['active_conversations'] ?? 0), 'detail' => $this->newConversationLabel($newConversations) . ' · ' . $this->compactTrend($c['active_conversations'] ?? null), 'tone' => 'primary'],
            ['label' => 'Respondidas', 'value' => $this->number($m['responded_conversations'] ?? 0), 'detail' => $this->compactTrend($c['responded_conversations'] ?? null), 'tone' => 'primary'],
            ['label' => 'Atendimentos humanos', 'value' => $this->number($m['human_conversations'] ?? 0), 'detail' => $this->number($m['human_replies'] ?? 0) . ' respostas da equipe', 'tone' => 'accent'],
            ['label' => '1ª resposta', 'value' => $measuredResponses > 0 ? $this->duration($m['avg_first_response_seconds'] ?? 0) : 'Não mensurado', 'detail' => $measuredResponses > 0 ? $this->plural($measuredResponses, 'resposta medida', 'respostas medidas') : 'Nenhum ciclo com tempo disponível', 'tone' => 'primary'],
            ['label' => 'Agendamentos', 'value' => $this->number($m['appointments'] ?? 0), 'detail' => $this->percent($m['appointment_success_rate'] ?? $m['agenda_conversion'] ?? 0) . ' de resultado', 'tone' => 'accent'],
            ['label' => 'Comparecimento', 'value' => $this->percent($m['attendance_rate'] ?? 0), 'detail' => $this->completedAppointmentsDetail((int) ($m['appointments_completed'] ?? 0)), 'tone' => 'accent'],
            ['label' => 'Respostas da IA', 'value' => $this->number($m['ai_replies'] ?? 0), 'detail' => (int) ($m['ai_replies'] ?? 0) === 0 ? 'Nenhuma resposta da IA no período' : $this->percent($m['ai_share'] ?? 0) . ' das respostas atribuídas', 'tone' => 'primary'],
            ['label' => 'Atenção', 'value' => $this->number($attention), 'detail' => $attention === 0 ? 'Nenhuma conversa pendente' : $this->attentionDetail($attentionHuman, $unread), 'tone' => 'attention'],
        ];
    }

    private function renderAdminSections(
        SimplePdfDocument $pdf,
        float &$y,
        array $data,
        array $sections,
        string $primary,
        string $secondary,
        string $accent,
        string $name,
        string $period
    ): void {
        if (in_array('companies', $sections, true)) {
            $this->ensureSpace($pdf, $y, 145, $name, $period, $primary);
            $y = $this->sectionTitle($pdf, $y, 'Desempenho por empresa', 'Empresas com maior movimento no período.', $primary);
            $rows = array_map(fn (array $row): array => [
                (string) ($row['name'] ?? 'Empresa'),
                $this->number($row['conversations'] ?? 0),
                $this->number($row['messages'] ?? 0),
                $this->number($row['ai_replies'] ?? 0),
            ], array_slice(is_array($data['usageByTenant'] ?? null) ? $data['usageByTenant'] : [], 0, 10));
            $y = $this->table($pdf, $y, ['Empresa', 'Conversas', 'Interações', 'IA'], $rows, [265, 78, 84, 70], $primary);
        }

        if (in_array('usage', $sections, true)) {
            $this->ensureSpace($pdf, $y, 155, $name, $period, $primary);
            $y = $this->sectionTitle($pdf, $y, 'Atendimentos ao longo do tempo', 'Volume diário de mensagens processadas.', $primary);
            $series = is_array($data['messagesByDay'] ?? null) ? $data['messagesByDay'] : [];
            $y = $this->barList($pdf, $y, array_map(fn (array $row): array => [
                'label' => $this->dateBr((string) ($row['label'] ?? '')),
                'value' => (int) ($row['total'] ?? 0),
            ], array_slice($series, -10)), $primary, $accent);
        }

        if (in_array('health', $sections, true)) {
            $this->ensureSpace($pdf, $y, 160, $name, $period, $primary);
            $y = $this->sectionTitle($pdf, $y, 'Saúde da operação', 'Distribuição atual das empresas e pontos de atenção.', $primary);
            $rows = array_map(fn (array $row): array => [(string) ($row['label'] ?? ''), $this->number($row['total'] ?? 0)], is_array($data['healthDistribution'] ?? null) ? $data['healthDistribution'] : []);
            $y = $this->table($pdf, $y, ['Situação', 'Empresas'], $rows, [390, 107], $primary);
        }

        if (in_array('automation', $sections, true)) {
            $this->ensureSpace($pdf, $y, 145, $name, $period, $primary);
            $y = $this->sectionTitle($pdf, $y, 'IA e automações', 'Ocorrências que precisam de acompanhamento.', $primary);
            $rows = array_map(fn (array $row): array => [(string) ($row['source'] ?? ''), (string) ($row['label'] ?? ''), $this->number($row['total'] ?? 0)], array_slice(is_array($data['failures'] ?? null) ? $data['failures'] : [], 0, 10));
            $y = $this->table($pdf, $y, ['Origem', 'Situação', 'Total'], $rows, [100, 320, 77], $primary);
        }

        if (in_array('agenda', $sections, true)) {
            $this->ensureSpace($pdf, $y, 145, $name, $period, $primary);
            $y = $this->sectionTitle($pdf, $y, 'Agenda', 'Situação dos compromissos no período.', $primary);
            $rows = array_map(fn (array $row): array => [$this->friendlyStatus((string) ($row['label'] ?? '')), $this->number($row['total'] ?? 0)], is_array($data['agendaStatus'] ?? null) ? $data['agendaStatus'] : []);
            $y = $this->table($pdf, $y, ['Situação', 'Compromissos'], $rows, [390, 107], $primary);
        }

        if (in_array('commercial', $sections, true)) {
            $this->ensureSpace($pdf, $y, 145, $name, $period, $primary);
            $y = $this->sectionTitle($pdf, $y, 'Comercial RS', 'Oportunidades por etapa e valor estimado.', $primary);
            $rows = array_map(fn (array $row): array => [(string) ($row['label'] ?? ''), $this->number($row['total'] ?? 0), $this->money($row['value'] ?? 0)], is_array($data['commercialStages'] ?? null) ? $data['commercialStages'] : []);
            $y = $this->table($pdf, $y, ['Etapa', 'Oportunidades', 'Valor'], $rows, [270, 105, 122], $secondary);
        }

        $this->renderInsights($pdf, $y, $data, $name, $period, $primary);
    }

    private function renderTenantSections(
        SimplePdfDocument $pdf,
        float &$y,
        array $data,
        array $sections,
        string $primary,
        string $secondary,
        string $accent,
        string $name,
        string $period
    ): void {
        if (in_array('conversations', $sections, true)) {
            $series = is_array($data['byDay'] ?? null) ? array_slice($data['byDay'], -10) : [];
            $this->ensureSpace($pdf, $y, 68 + (max(1, count($series)) * 20), $name, $period, $primary);
            $y = $this->sectionTitle($pdf, $y, 'Mensagens ao longo do tempo', 'Volume diário de mensagens registradas no período.', $primary);
            $y = $this->barList($pdf, $y, array_map(fn (array $row): array => [
                'label' => $this->dateBr((string) ($row['label'] ?? '')),
                'value' => (int) ($row['total'] ?? 0),
            ], $series), $primary, $secondary);
        }

        if (in_array('team', $sections, true)) {
            $teamRows = array_slice(is_array($data['teamPerformance'] ?? null) ? $data['teamPerformance'] : [], 0, 10);
            $this->ensureSpace($pdf, $y, 92 + (max(1, count($teamRows)) * 22), $name, $period, $primary);
            $y = $this->sectionTitle($pdf, $y, 'Desempenho da equipe', 'Atuação dos profissionais no período.', $primary);
            $rows = array_map(fn (array $row): array => [
                (string) ($row['label'] ?? 'Profissional'),
                $this->number($row['conversations'] ?? 0),
                $this->number($row['total'] ?? 0),
            ], $teamRows);
            $y = $this->table($pdf, $y, ['Profissional', 'Conversas', 'Respostas'], $rows, [315, 90, 92], $primary);
        }

        if (in_array('ai', $sections, true)) {
            $this->ensureSpace($pdf, $y, 180, $name, $period, $primary);
            $y = $this->sectionTitle($pdf, $y, 'IA e equipe', 'Participação nas respostas enviadas.', $primary);
            $m = is_array($data['metrics'] ?? null) ? $data['metrics'] : [];
            $rows = [
                ['Mensagens recebidas', $this->number($m['incoming_messages'] ?? 0)],
                ['Respostas da IA', $this->number($m['ai_replies'] ?? 0)],
                ['Respostas da equipe', $this->number($m['human_replies'] ?? 0)],
                ['Respostas do sistema', $this->number($m['system_replies'] ?? 0)],
            ];
            $y = $this->table($pdf, $y, ['Interação', 'Total'], $rows, [390, 107], $secondary);
        }

        if (in_array('agenda', $sections, true)) {
            $source = is_array($data['agendaResults'] ?? null) ? $data['agendaResults'] : (is_array($data['agendaByStatus'] ?? null) ? $data['agendaByStatus'] : []);
            if ($pdf->pageCount() === 1 && $y > 470 && count($source) >= 4) {
                $pdf->addPage();
                $this->continuationHeader($pdf, $name, $period, $primary);
                $y = 64;
            }
            $this->ensureSpace($pdf, $y, 92 + (max(1, count($source)) * 22), $name, $period, $primary);
            $y = $this->sectionTitle($pdf, $y, 'Resultado da agenda', 'Situação dos compromissos no período.', $primary);
            $rows = array_map(fn (array $row): array => [$this->friendlyStatus((string) ($row['label'] ?? '')), $this->number($row['total'] ?? 0)], $source);
            $y = $this->table($pdf, $y, ['Situação', 'Compromissos'], $rows, [390, 107], $primary);
        }

        if (in_array('attention', $sections, true)) {
            $attentionRows = array_slice(is_array($data['attention'] ?? null) ? $data['attention'] : [], 0, 10);
            $this->ensureSpace($pdf, $y, 92 + (max(1, count($attentionRows)) * 22), $name, $period, $primary);
            $y = $this->sectionTitle($pdf, $y, 'Conversas que precisam de atenção', 'Atendimentos humanos em aberto ou mensagens ainda não lidas.', $primary);
            $rows = array_map(fn (array $row): array => [
                (string) ($row['contact_name'] ?? 'Contato'),
                (string) ($row['phone'] ?? ''),
                $this->number($row['unread_count'] ?? 0),
                $this->friendlyStatus((string) ($row['status'] ?? 'open')),
            ], $attentionRows);
            $y = $this->table($pdf, $y, ['Contato', 'Telefone', 'Não lidas', 'Situação'], $rows, [185, 115, 60, 151], $primary);
        }

        $this->renderInsights($pdf, $y, $data, $name, $period, $primary);
    }

    private function renderInsights(SimplePdfDocument $pdf, float &$y, array $data, string $name, string $period, string $primary): void
    {
        $insights = is_array($data['insights'] ?? null) ? $data['insights'] : [];
        if ($insights === []) {
            return;
        }
        $this->ensureSpace($pdf, $y, 120, $name, $period, $primary);
        $y = $this->sectionTitle($pdf, $y, 'Leituras rápidas', 'Resumo automático dos principais movimentos.', $primary);
        foreach (array_slice($insights, 0, 5) as $insight) {
            $title = trim((string) ($insight['title'] ?? 'Insight'));
            $text = trim((string) ($insight['text'] ?? ''));
            $lines = $pdf->wrap($text, self::CONTENT_WIDTH - 48, 8.2, false);
            $cardHeight = max(64.0, 43.0 + (min(3, count($lines)) * 11.5));
            $pdf->rect(self::MARGIN, $y, self::CONTENT_WIDTH, $cardHeight, '#F8FBFF', '#DDE6F3', 0.55);
            $pdf->rect(self::MARGIN, $y, 4, $cardHeight, '#7B3FF2', null);
            $pdf->rect(self::MARGIN + 18, $y + 15, 22, 22, '#EAF3FF', null);
            $pdf->text(self::MARGIN + 25, $y + 20, 'i', 9.5, true, '#2F80FF');
            $pdf->text(self::MARGIN + 52, $y + 14, $title, 9.3, true, '#172033');
            $pdf->paragraph(self::MARGIN + 52, $y + 31, self::CONTENT_WIDTH - 70, $text, 8.2, 11.5, false, '#566176', 3);
            $y += $cardHeight + 10;
        }
    }

    private function sectionTitle(SimplePdfDocument $pdf, float $y, string $title, string $subtitle, string $primary): float
    {
        $pdf->rect(self::MARGIN, $y, 30, 3, '#7B3FF2', null);
        $pdf->text(self::MARGIN, $y + 10, $title, 12.5, true, '#172033');
        $pdf->text(self::MARGIN, $y + 29, $subtitle, 7.8, false, '#687589');
        return $y + 48;
    }

    /**
     * @param list<string> $headers
     * @param list<list<string>> $rows
     * @param list<int|float> $widths
     */
    private function table(SimplePdfDocument $pdf, float $y, array $headers, array $rows, array $widths, string $primary): float
    {
        $rowHeight = 22.0;
        $headerHeight = 24.0;
        $pdf->rect(self::MARGIN, $y, self::CONTENT_WIDTH, $headerHeight, '#F0F5FF', '#DDE6F3', 0.5);
        $pdf->rect(self::MARGIN, $y, self::CONTENT_WIDTH, 2.5, $primary, null);
        $x = self::MARGIN;
        foreach ($headers as $index => $header) {
            $pdf->text($x + 6, $y + 8, $header, 7.2, true, '#42526A');
            $x += (float) ($widths[$index] ?? 80);
        }
        $y += $headerHeight;

        if ($rows === []) {
            $pdf->rect(self::MARGIN, $y, self::CONTENT_WIDTH, 34, '#f8fafc', '#dfe6ef', 0.5);
            $pdf->text(self::MARGIN + 8, $y + 11, 'Nenhum dado encontrado no período.', 8.2, false, '#687589');
            return $y + 45;
        }

        foreach ($rows as $rowIndex => $row) {
            $fill = $rowIndex % 2 === 0 ? '#ffffff' : '#F8FAFF';
            $pdf->rect(self::MARGIN, $y, self::CONTENT_WIDTH, $rowHeight, $fill, '#E5EAF2', 0.35);
            $x = self::MARGIN;
            foreach ($headers as $index => $_header) {
                $value = (string) ($row[$index] ?? '');
                $pdf->paragraph($x + 6, $y + 6, max(20, (float) ($widths[$index] ?? 80) - 10), $value, 7.2, 9, $index === 0, '#2B3548', 1);
                $x += (float) ($widths[$index] ?? 80);
            }
            $y += $rowHeight;
        }
        return $y + 13;
    }

    /**
     * @param list<array{label:string,value:int}> $items
     */
    private function barList(SimplePdfDocument $pdf, float $y, array $items, string $primary, string $accent): float
    {
        if ($items === []) {
            $pdf->rect(self::MARGIN, $y, self::CONTENT_WIDTH, 38, '#f8fafc', '#dfe6ef', 0.5);
            $pdf->text(self::MARGIN + 8, $y + 13, 'Nenhum movimento registrado no período.', 8.2, false, '#687589');
            return $y + 50;
        }
        $max = max(1, ...array_map(static fn (array $item): int => (int) $item['value'], $items));
        foreach ($items as $index => $item) {
            $label = (string) $item['label'];
            $value = (int) $item['value'];
            $pdf->text(self::MARGIN, $y + 3, $label, 7.3, true, '#445066');
            $pdf->rect(self::MARGIN + 72, $y, 365, 12, '#edf1f6', null);
            $width = $value > 0 ? max(2, ($value / $max) * 365) : 0;
            if ($width > 0) {
                $pdf->rect(self::MARGIN + 72, $y, $width, 12, $index % 2 === 0 ? $primary : $accent, null);
            }
            $pdf->text(self::MARGIN + 450, $y + 2, $this->number($value), 7.5, true, '#172033');
            $y += 20;
        }
        return $y + 9;
    }

    private function ensureSpace(SimplePdfDocument $pdf, float &$y, float $needed, string $name, string $period, string $primary): void
    {
        if ($y + $needed <= 790) {
            return;
        }
        $pdf->addPage();
        $this->continuationHeader($pdf, $name, $period, $primary);
        $y = 64;
    }

    /** @return array<string,mixed> */
    private function summaryMetrics(string $scope, array $metrics): array
    {
        if ($scope === 'admin') {
            return [
                'active_companies' => (int) ($metrics['active_companies'] ?? 0),
                'conversations_started' => (int) ($metrics['conversations_started'] ?? 0),
                'human_conversations' => (int) ($metrics['human_conversations'] ?? 0),
                'appointments' => (int) ($metrics['appointments'] ?? 0),
                'ai_replies' => (int) ($metrics['ai_replies'] ?? 0),
                'open_incidents' => (int) ($metrics['open_operational_incidents'] ?? 0),
            ];
        }
        return [
            'active_conversations' => (int) ($metrics['active_conversations'] ?? 0),
            'conversations' => (int) ($metrics['conversations'] ?? 0),
            'responded_conversations' => (int) ($metrics['responded_conversations'] ?? 0),
            'human_conversations' => (int) ($metrics['human_conversations'] ?? 0),
            'appointments' => (int) ($metrics['appointments'] ?? 0),
            'ai_replies' => (int) ($metrics['ai_replies'] ?? 0),
            'unread' => (int) ($metrics['unread'] ?? 0),
            'attention_conversations' => (int) ($metrics['attention_conversations'] ?? 0),
            'attention_human_conversations' => (int) ($metrics['attention_human_conversations'] ?? 0),
        ];
    }

    private function newConversationLabel(int $count): string
    {
        return match ($count) {
            0 => 'Nenhuma nova',
            1 => '1 conversa nova',
            default => $this->number($count) . ' conversas novas',
        };
    }

    private function compactTrend(mixed $value): string
    {
        if ($value === null || $value === '') return 'sem base anterior';
        $number = (float) $value;
        if (abs($number) < 0.05) return 'estável vs. anterior';
        return ($number > 0 ? '+' : '-') . number_format(abs($number), 1, ',', '.') . '% vs. anterior';
    }

    private function attentionDetail(int $human, int $unread): string
    {
        $humanLabel = $human === 1 ? '1 em atendimento humano' : $this->number($human) . ' em atendimento humano';
        $unreadLabel = $unread === 0 ? 'nenhuma mensagem não lida' : $this->plural($unread, 'mensagem não lida', 'mensagens não lidas');
        return $humanLabel . ' · ' . $unreadLabel;
    }

    private function plural(int $count, string $singular, string $plural): string
    {
        return $this->number($count) . ' ' . ($count === 1 ? $singular : $plural);
    }

    private function completedAppointmentsDetail(int $count): string
    {
        return match ($count) {
            0 => 'Não há atividade no período',
            1 => '1 compromisso concluído',
            default => $this->number($count) . ' compromissos concluídos',
        };
    }

    private function automationFailuresDetail(int $count): string
    {
        return match ($count) {
            0 => 'Nenhuma falha no período',
            1 => '1 falha no período',
            default => $this->number($count) . ' falhas no período',
        };
    }

    private function logoPath(): string
    {
        return dirname(__DIR__, 2) . '/public/assets/img/rs-connect-report-mark.jpg';
    }

    private function friendlyStatus(string $status): string
    {
        return match (strtolower(trim($status))) {
            'open', 'active' => 'Em aberto',
            'pending' => 'Aguardando atendimento',
            'closed' => 'Encerrado',
            'scheduled' => 'Agendado',
            'confirmed' => 'Confirmado',
            'completed' => 'Concluído',
            'cancelled' => 'Cancelado',
            'no_show' => 'Não compareceu',
            'rejected' => 'Rejeitado',
            'healthy' => 'Saudável',
            'attention', 'warning' => 'Atenção',
            'critical', 'down' => 'Ação imediata',
            default => $status !== '' ? ucfirst(str_replace('_', ' ', $status)) : 'Não informado',
        };
    }

    private function trend(mixed $value, string $fallback): string
    {
        if ($value === null || $value === '') {
            return $fallback;
        }
        $number = (float) $value;
        if (abs($number) < 0.05) {
            return 'Estável vs. período anterior';
        }
        return ($number > 0 ? '+' : '-') . number_format(abs($number), 1, ',', '.') . '% vs. período anterior';
    }

    private function number(mixed $value): string
    {
        return number_format((float) $value, 0, ',', '.');
    }

    private function percent(mixed $value): string
    {
        return number_format((float) $value, 1, ',', '.') . '%';
    }

    private function money(mixed $value): string
    {
        return 'R$ ' . number_format((float) $value, 2, ',', '.');
    }

    private function duration(mixed $value): string
    {
        $seconds = max(0, (int) round((float) $value));
        if ($seconds <= 0) return 'Sem dados';
        if ($seconds < 60) return $seconds . 's';
        if ($seconds < 3600) return intdiv($seconds, 60) . 'min ' . ($seconds % 60) . 's';
        return intdiv($seconds, 3600) . 'h ' . intdiv($seconds % 3600, 60) . 'min';
    }

    private function dateBr(string $date): string
    {
        $timestamp = strtotime($date);
        return $timestamp ? date('d/m/Y', $timestamp) : ($date !== '' ? $date : '—');
    }

    private function color(string $value, string $fallback): string
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', trim($value)) === 1 ? trim($value) : $fallback;
    }
}
