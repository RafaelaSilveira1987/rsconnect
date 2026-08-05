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
        $primary = $this->color((string) ($identity['primary'] ?? '#146498'), '#146498');
        $secondary = $this->color((string) ($identity['secondary'] ?? '#631b7c'), '#631b7c');
        $accent = $this->color((string) ($identity['accent'] ?? '#01c5b6'), '#01c5b6');
        $name = trim((string) ($identity['name'] ?? ($scope === 'admin' ? 'RS Connect' : 'Empresa')));
        $title = trim((string) ($identity['report_title'] ?? 'Relatório executivo'));
        $periodLabel = $this->dateBr((string) ($filters['start'] ?? '')) . ' a ' . $this->dateBr((string) ($filters['end'] ?? ''));

        $pdf = new SimplePdfDocument();
        $pdf->addPage();
        $this->header($pdf, $title, $name, $periodLabel, $primary, $secondary);
        $y = 128.0;

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
        $pdf->rect(0, 0, SimplePdfDocument::PAGE_WIDTH, 104, $primary, null);
        $pdf->rect(0, 96, SimplePdfDocument::PAGE_WIDTH, 8, $secondary, null);
        $pdf->text(self::MARGIN, 22, mb_strtoupper($name), 9, true, '#ffffff');
        $pdf->text(self::MARGIN, 43, $title, 22, true, '#ffffff');
        $pdf->text(self::MARGIN, 73, 'Período: ' . $period, 9.5, false, '#eef7ff');
        $pdf->text(422, 23, 'RS CONNECT', 8.5, true, '#ffffff');
        $pdf->text(422, 42, 'RELATÓRIO PDF', 8, false, '#d8efff');
        $pdf->text(422, 60, 'Gerado em ' . date('d/m/Y H:i'), 7.5, false, '#d8efff');
    }

    private function continuationHeader(SimplePdfDocument $pdf, string $name, string $period, string $primary): void
    {
        $pdf->rect(0, 0, SimplePdfDocument::PAGE_WIDTH, 58, $primary, null);
        $pdf->text(self::MARGIN, 17, $name, 13, true, '#ffffff');
        $pdf->text(self::MARGIN, 37, 'Relatório executivo · ' . $period, 8, false, '#e8f5ff');
    }

    /**
     * @param list<array{label:string,value:string,detail:string,tone:string}> $kpis
     */
    private function kpiGrid(SimplePdfDocument $pdf, float $y, array $kpis, string $primary, string $accent): float
    {
        $gap = 11.0;
        $width = (self::CONTENT_WIDTH - $gap) / 2;
        $height = 64.0;
        foreach ($kpis as $index => $kpi) {
            $col = $index % 2;
            $row = intdiv($index, 2);
            $x = self::MARGIN + ($col * ($width + $gap));
            $top = $y + ($row * ($height + $gap));
            $tone = $kpi['tone'] === 'accent' ? $accent : $primary;
            $pdf->rect($x, $top, $width, $height, '#f8fafc', '#dfe6ef', 0.7);
            $pdf->rect($x, $top, 5, $height, $tone, null);
            $pdf->text($x + 16, $top + 12, mb_strtoupper($kpi['label']), 7.2, true, '#677286');
            $pdf->text($x + 16, $top + 28, $kpi['value'], 17, true, '#172033');
            $pdf->text($x + 16, $top + 49, $kpi['detail'], 7.3, false, '#687589');
        }
        return $y + (ceil(count($kpis) / 2) * ($height + $gap)) + 6;
    }

    /** @return list<array{label:string,value:string,detail:string,tone:string}> */
    private function adminKpis(array $m, array $c): array
    {
        return [
            ['label' => 'Empresas ativas', 'value' => $this->number($m['active_companies'] ?? 0), 'detail' => $this->trend($c['new_companies'] ?? null, 'novas no período'), 'tone' => 'primary'],
            ['label' => 'Conversas iniciadas', 'value' => $this->number($m['conversations_started'] ?? 0), 'detail' => $this->trend($c['conversations_started'] ?? null, 'comparação anterior'), 'tone' => 'primary'],
            ['label' => 'Atendimentos humanos', 'value' => $this->number($m['human_conversations'] ?? 0), 'detail' => $this->number($m['human_messages'] ?? 0) . ' respostas da equipe', 'tone' => 'accent'],
            ['label' => 'Primeira resposta', 'value' => $this->duration($m['avg_first_response_seconds'] ?? 0), 'detail' => $this->number($m['first_responses_measured'] ?? $m['first_responses'] ?? 0) . ' respostas medidas', 'tone' => 'primary'],
            ['label' => 'Agendamentos', 'value' => $this->number($m['appointments'] ?? 0), 'detail' => $this->percent($m['agenda_conversion'] ?? 0) . ' confirmados/concluídos', 'tone' => 'accent'],
            ['label' => 'Comparecimento', 'value' => $this->percent($m['attendance_rate'] ?? 0), 'detail' => $this->number($m['appointments_completed'] ?? 0) . ' concluído(s)', 'tone' => 'accent'],
            ['label' => 'Uso da IA', 'value' => $this->number($m['ai_replies'] ?? 0), 'detail' => $this->percent($m['ai_share'] ?? 0) . ' das respostas', 'tone' => 'primary'],
            ['label' => 'Incidentes operacionais', 'value' => $this->number($m['open_operational_incidents'] ?? 0), 'detail' => $this->number($m['automation_failures'] ?? 0) . ' falha(s) no período', 'tone' => 'primary'],
        ];
    }

    /** @return list<array{label:string,value:string,detail:string,tone:string}> */
    private function tenantKpis(array $m, array $c): array
    {
        return [
            ['label' => 'Conversas atendidas', 'value' => $this->number($m['active_conversations'] ?? 0), 'detail' => $this->number($m['conversations'] ?? 0) . ' nova(s) · ' . $this->trend($c['active_conversations'] ?? null, 'sem base anterior'), 'tone' => 'primary'],
            ['label' => 'Conversas respondidas', 'value' => $this->number($m['responded_conversations'] ?? 0), 'detail' => $this->trend($c['responded_conversations'] ?? null, 'comparação anterior'), 'tone' => 'primary'],
            ['label' => 'Atendimentos humanos', 'value' => $this->number($m['human_conversations'] ?? 0), 'detail' => $this->number($m['human_replies'] ?? 0) . ' respostas da equipe', 'tone' => 'accent'],
            ['label' => 'Primeira resposta', 'value' => $this->duration($m['avg_first_response_seconds'] ?? 0), 'detail' => $this->number($m['first_responses_measured'] ?? $m['first_responses'] ?? 0) . ' respostas medidas', 'tone' => 'primary'],
            ['label' => 'Agendamentos', 'value' => $this->number($m['appointments'] ?? 0), 'detail' => $this->percent($m['appointment_success_rate'] ?? $m['agenda_conversion'] ?? 0) . ' de resultado', 'tone' => 'accent'],
            ['label' => 'Comparecimento', 'value' => $this->percent($m['attendance_rate'] ?? 0), 'detail' => $this->number($m['appointments_completed'] ?? 0) . ' concluído(s)', 'tone' => 'accent'],
            ['label' => 'Uso da IA', 'value' => $this->number($m['ai_replies'] ?? 0), 'detail' => $this->percent($m['ai_share'] ?? 0) . ' das respostas', 'tone' => 'primary'],
            ['label' => 'Conversas que precisam de atenção', 'value' => $this->number($m['attention_conversations'] ?? 0), 'detail' => $this->number($m['unread'] ?? 0) . ' mensagem(ns) não lida(s)', 'tone' => 'primary'],
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
            $this->ensureSpace($pdf, $y, 275, $name, $period, $primary);
            $y = $this->sectionTitle($pdf, $y, 'Mensagens ao longo do tempo', 'Volume diário de mensagens registradas no período.', $primary);
            $series = is_array($data['byDay'] ?? null) ? $data['byDay'] : [];
            $y = $this->barList($pdf, $y, array_map(fn (array $row): array => [
                'label' => $this->dateBr((string) ($row['label'] ?? '')),
                'value' => (int) ($row['total'] ?? 0),
            ], array_slice($series, -10)), $primary, $accent);
        }

        if (in_array('team', $sections, true)) {
            $this->ensureSpace($pdf, $y, 320, $name, $period, $primary);
            $y = $this->sectionTitle($pdf, $y, 'Desempenho da equipe', 'Atuação dos profissionais no período.', $primary);
            $rows = array_map(fn (array $row): array => [
                (string) ($row['label'] ?? 'Profissional'),
                $this->number($row['conversations'] ?? 0),
                $this->number($row['total'] ?? 0),
            ], array_slice(is_array($data['teamPerformance'] ?? null) ? $data['teamPerformance'] : [], 0, 10));
            $y = $this->table($pdf, $y, ['Profissional', 'Conversas', 'Respostas'], $rows, [315, 90, 92], $primary);
        }

        if (in_array('agenda', $sections, true)) {
            $this->ensureSpace($pdf, $y, 245, $name, $period, $primary);
            $y = $this->sectionTitle($pdf, $y, 'Resultado da agenda', 'Confirmações, conclusões e ausências.', $primary);
            $source = is_array($data['agendaResults'] ?? null) ? $data['agendaResults'] : (is_array($data['agendaByStatus'] ?? null) ? $data['agendaByStatus'] : []);
            $rows = array_map(fn (array $row): array => [$this->friendlyStatus((string) ($row['label'] ?? '')), $this->number($row['total'] ?? 0)], $source);
            $y = $this->table($pdf, $y, ['Situação', 'Compromissos'], $rows, [390, 107], $primary);
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

        if (in_array('attention', $sections, true)) {
            $this->ensureSpace($pdf, $y, 310, $name, $period, $primary);
            $y = $this->sectionTitle($pdf, $y, 'Conversas que precisam de atenção', 'Itens ainda abertos ou com mensagens não lidas.', $primary);
            $rows = array_map(fn (array $row): array => [
                (string) ($row['contact_name'] ?? 'Contato'),
                (string) ($row['phone'] ?? ''),
                $this->number($row['unread_count'] ?? 0),
                $this->friendlyStatus((string) ($row['status'] ?? 'open')),
            ], array_slice(is_array($data['attention'] ?? null) ? $data['attention'] : [], 0, 10));
            $y = $this->table($pdf, $y, ['Contato', 'Telefone', 'Não lidas', 'Situação'], $rows, [220, 120, 70, 87], $primary);
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
            $pdf->text(self::MARGIN + 8, $y, $title, 9, true, '#172033');
            $used = $pdf->paragraph(self::MARGIN + 8, $y + 14, self::CONTENT_WIDTH - 16, $text, 8.2, 11.5, false, '#566176', 3);
            $y += 20 + $used;
        }
    }

    private function sectionTitle(SimplePdfDocument $pdf, float $y, string $title, string $subtitle, string $primary): float
    {
        $pdf->rect(self::MARGIN, $y, 5, 38, $primary, null);
        $pdf->text(self::MARGIN + 15, $y + 2, $title, 13, true, '#172033');
        $pdf->text(self::MARGIN + 15, $y + 21, $subtitle, 8, false, '#687589');
        return $y + 50;
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
        $pdf->rect(self::MARGIN, $y, self::CONTENT_WIDTH, $headerHeight, $primary, null);
        $x = self::MARGIN;
        foreach ($headers as $index => $header) {
            $pdf->text($x + 6, $y + 7, $header, 7.4, true, '#ffffff');
            $x += (float) ($widths[$index] ?? 80);
        }
        $y += $headerHeight;

        if ($rows === []) {
            $pdf->rect(self::MARGIN, $y, self::CONTENT_WIDTH, 34, '#f8fafc', '#dfe6ef', 0.5);
            $pdf->text(self::MARGIN + 8, $y + 11, 'Nenhum dado encontrado no período.', 8.2, false, '#687589');
            return $y + 45;
        }

        foreach ($rows as $rowIndex => $row) {
            $fill = $rowIndex % 2 === 0 ? '#ffffff' : '#f7f9fc';
            $pdf->rect(self::MARGIN, $y, self::CONTENT_WIDTH, $rowHeight, $fill, '#e5eaf1', 0.35);
            $x = self::MARGIN;
            foreach ($headers as $index => $_header) {
                $value = (string) ($row[$index] ?? '');
                $pdf->paragraph($x + 6, $y + 6, max(20, (float) ($widths[$index] ?? 80) - 10), $value, 7.4, 9, $index === 0, '#2b3548', 1);
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
        $y = 78;
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
        ];
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
