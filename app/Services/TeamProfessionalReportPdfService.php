<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;

/**
 * PDF legível para o relatório Equipe e profissionais.
 * Reaproveita os dados já calculados pelo TeamProfessionalReportService e não
 * executa consultas próprias, mantendo PDF, tela e CSV na mesma fonte.
 */
final class TeamProfessionalReportPdfService
{
    private const MARGIN = 42.0;
    private const CONTENT_WIDTH = 511.0;

    /**
     * @param array<string,mixed> $filters
     * @param array<string,mixed> $data
     * @param array<string,mixed> $identity
     * @return array{bytes:string,summary:array<string,mixed>}
     */
    public function generate(array $filters, array $data, array $identity): array
    {
        $primary = $this->color((string) ($identity['primary'] ?? ''), '#2F80FF');
        $secondary = $this->color((string) ($identity['secondary'] ?? ''), '#7B3FF2');
        $accent = $this->color((string) ($identity['accent'] ?? ''), '#14B8A6');
        $company = trim((string) ($identity['name'] ?? ($data['tenant']['name'] ?? 'Empresa')));
        if ($company === '') {
            $company = 'Empresa';
        }
        $period = $this->dateBr((string) ($filters['start'] ?? '')) . ' a ' . $this->dateBr((string) ($filters['end'] ?? ''));
        $overview = is_array($data['overview'] ?? null) ? $data['overview'] : [];
        $professionals = is_array($data['professionals'] ?? null) ? $data['professionals'] : [];
        $departments = is_array($data['departmentPerformance'] ?? null) ? $data['departmentPerformance'] : [];
        $audit = is_array($data['responseAudit'] ?? null) ? $data['responseAudit'] : [];
        $warnings = is_array($data['warnings'] ?? null) ? array_values(array_filter($data['warnings'], 'is_string')) : [];

        $pdf = new SimplePdfDocument();
        $pdf->addPage();
        $this->header($pdf, $company, $period, $primary, $secondary);
        $y = 116.0;

        $cards = [
            ['Profissionais', $this->number($overview['professionals'] ?? count($professionals)), 'equipe no escopo'],
            ['Mensagens humanas', $this->number($overview['human_messages'] ?? 0), 'respostas da equipe'],
            ['1ª resposta', $this->duration($overview['avg_first_response_seconds'] ?? 0), $this->number($overview['first_responses'] ?? 0) . ' medidas'],
            ['SLA', $this->percent($overview['sla_compliance'] ?? 0), 'meta de ' . $this->number($overview['sla_target_minutes'] ?? ($filters['sla_minutes'] ?? 30)) . ' min'],
            ['Duração do ciclo', $this->duration($overview['avg_service_duration_seconds'] ?? 0), $this->number($overview['service_cycles_closed'] ?? 0) . ' ciclos'],
            ['Aguardando agora', $this->number($overview['waiting_now'] ?? 0), $this->number($overview['waiting_over_sla'] ?? 0) . ' fora do SLA'],
        ];
        $y = $this->cards($pdf, $y, $cards, $primary, $secondary, $accent);

        $this->ensureSpace($pdf, $y, 120, $company, $period, $primary, $secondary);
        $y = $this->sectionTitle($pdf, $y, 'Equipe e profissionais', 'Desempenho humano consolidado no período.', $primary);
        $rows = [];
        foreach (array_slice($professionals, 0, 18) as $row) {
            $rows[] = [
                (string) ($row['name'] ?? 'Profissional'),
                $this->number($row['conversations_replied'] ?? 0),
                $this->duration($row['avg_first_response_seconds'] ?? 0),
                $this->percent($row['sla_compliance'] ?? 0),
                $this->number($row['closed_conversations'] ?? 0),
                $this->number($row['appointments'] ?? 0),
            ];
        }
        $y = $this->table(
            $pdf,
            $y,
            ['Profissional', 'Conversas', '1ª resposta', 'SLA', 'Encerradas', 'Agenda'],
            $rows,
            [180, 64, 78, 55, 65, 55],
            $primary,
            $company,
            $period,
            $secondary
        );

        if (!empty($data['queue_enabled']) && $departments !== []) {
            $this->ensureSpace($pdf, $y, 120, $company, $period, $primary, $secondary);
            $y = $this->sectionTitle($pdf, $y, 'Carga atual por setor', 'Fotografia operacional das filas ativas.', $primary);
            $rows = [];
            foreach (array_slice($departments, 0, 14) as $row) {
                $rows[] = [
                    (string) ($row['name'] ?? 'Setor'),
                    $this->number($row['active_members'] ?? 0),
                    $this->number($row['open_conversations'] ?? 0),
                    $this->number($row['waiting_now'] ?? 0),
                    $this->number($row['waiting_over_sla'] ?? 0),
                    $this->duration($row['avg_current_wait_seconds'] ?? 0),
                ];
            }
            $y = $this->table(
                $pdf,
                $y,
                ['Setor', 'Equipe', 'Abertas', 'Aguardando', 'Fora SLA', 'Espera média'],
                $rows,
                [170, 58, 62, 72, 65, 70],
                $accent,
                $company,
                $period,
                $secondary
            );
        }

        if ($audit !== []) {
            $this->ensureSpace($pdf, $y, 120, $company, $period, $primary, $secondary);
            $y = $this->sectionTitle($pdf, $y, 'Amostra de primeiras respostas', 'Ciclos recentes usados na conferência operacional.', $primary);
            $rows = [];
            foreach (array_slice($audit, 0, 14) as $row) {
                $rows[] = [
                    (string) ($row['contact_name'] ?? 'Contato'),
                    (string) ($row['professional_name'] ?? 'Equipe'),
                    $this->duration($row['response_seconds'] ?? 0),
                    !empty($row['within_sla']) ? 'Dentro' : 'Fora',
                    (string) ($row['first_response_at_local'] ?? $row['first_response_at'] ?? ''),
                ];
            }
            $y = $this->table(
                $pdf,
                $y,
                ['Contato', 'Profissional', 'Tempo', 'SLA', 'Resposta'],
                $rows,
                [145, 130, 70, 55, 97],
                $secondary,
                $company,
                $period,
                $secondary
            );
        }

        if ($warnings !== []) {
            $this->ensureSpace($pdf, $y, 90, $company, $period, $primary, $secondary);
            $y = $this->sectionTitle($pdf, $y, 'Observações', 'Pontos que merecem revisão dos dados.', $primary);
            foreach (array_slice($warnings, 0, 5) as $warning) {
                $height = $pdf->paragraph(self::MARGIN + 8, $y, self::CONTENT_WIDTH - 16, '• ' . $warning, 8.2, 11.5, false, '#72501C', 3);
                $y += $height + 4;
            }
        }

        $bytes = $pdf->output();
        return [
            'bytes' => $bytes,
            'summary' => [
                'period_start' => (string) ($filters['start'] ?? ''),
                'period_end' => (string) ($filters['end'] ?? ''),
                'professionals' => count($professionals),
                'pages' => $pdf->pageCount(),
                'size_bytes' => strlen($bytes),
                'generated_at' => (new DateTimeImmutable('now'))->format(DATE_ATOM),
            ],
        ];
    }

    /** @param list<array{0:string,1:string,2:string}> $cards */
    private function cards(SimplePdfDocument $pdf, float $y, array $cards, string $primary, string $secondary, string $accent): float
    {
        $columns = 3;
        $gap = 9.0;
        $width = (self::CONTENT_WIDTH - (($columns - 1) * $gap)) / $columns;
        $height = 66.0;
        foreach ($cards as $index => $card) {
            $col = $index % $columns;
            $row = intdiv($index, $columns);
            $x = self::MARGIN + ($col * ($width + $gap));
            $top = $y + ($row * ($height + $gap));
            $tone = [$primary, $secondary, $accent][$index % 3];
            $pdf->rect($x, $top, $width, $height, '#F8FBFF', '#DDE6F3', 0.6);
            $pdf->rect($x, $top, $width, 3, $tone, null);
            $pdf->text($x + 10, $top + 11, $this->upper($card[0]), 6.8, true, '#687589');
            $pdf->text($x + 10, $top + 29, $card[1], 15.0, true, '#172033');
            $pdf->paragraph($x + 10, $top + 49, $width - 20, $card[2], 7.0, 8.5, false, '#687589', 1);
        }
        return $y + (ceil(count($cards) / $columns) * ($height + $gap)) + 6;
    }

    private function header(SimplePdfDocument $pdf, string $company, string $period, string $primary, string $secondary): void
    {
        $pdf->rect(0, 0, 370, 6, $primary, null);
        $pdf->rect(370, 0, SimplePdfDocument::PAGE_WIDTH - 370, 6, $secondary, null);
        $logo = dirname(__DIR__, 2) . '/public/assets/img/rs-connect-report-mark.jpg';
        if (is_file($logo)) {
            $pdf->jpeg($logo, self::MARGIN, 14, 64, 56);
        }
        $pdf->text(116, 22, 'RS CONNECT', 9, true, '#172033');
        $pdf->text(116, 39, 'EQUIPE E PROFISSIONAIS', 12, true, $secondary);
        $pdf->paragraph(116, 57, 210, $company, 8.2, 10.0, false, '#566176', 1);
        $pdf->text(366, 24, 'PERÍODO', 6.8, true, '#7A8496');
        $pdf->text(366, 38, $period, 9.0, true, '#253047');
        $pdf->text(366, 59, 'GERADO EM', 6.8, true, '#7A8496');
        $pdf->text(366, 73, date('d/m/Y H:i'), 8.0, false, '#4F5B70');
        $pdf->line(self::MARGIN, 100, self::MARGIN + self::CONTENT_WIDTH, 100, '#E3E8F2', 0.7);
    }

    private function continuationHeader(SimplePdfDocument $pdf, string $company, string $period, string $primary, string $secondary): void
    {
        $pdf->rect(0, 0, 370, 5, $primary, null);
        $pdf->rect(370, 0, SimplePdfDocument::PAGE_WIDTH - 370, 5, $secondary, null);
        $pdf->text(self::MARGIN, 17, 'RS CONNECT · EQUIPE E PROFISSIONAIS', 8.0, true, '#172033');
        $pdf->paragraph(260, 17, 155, $company, 7.2, 8.5, false, '#687589', 1);
        $pdf->text(430, 17, $period, 7.2, false, '#687589');
        $pdf->line(self::MARGIN, 43, self::MARGIN + self::CONTENT_WIDTH, 43, '#E3E8F2', 0.7);
    }

    private function sectionTitle(SimplePdfDocument $pdf, float $y, string $title, string $subtitle, string $primary): float
    {
        $pdf->rect(self::MARGIN, $y, 30, 3, $primary, null);
        $pdf->text(self::MARGIN, $y + 10, $title, 12, true, '#172033');
        $pdf->paragraph(self::MARGIN, $y + 28, self::CONTENT_WIDTH, $subtitle, 7.7, 10, false, '#687589', 2);
        return $y + 48;
    }

    /** @param list<string> $headers @param list<list<string>> $rows @param list<int|float> $widths */
    private function table(SimplePdfDocument $pdf, float $y, array $headers, array $rows, array $widths, string $tone, string $company, string $period, string $secondary): float
    {
        $headerHeight = 24.0;
        $rowHeight = 23.0;
        if ($rows === []) {
            $pdf->rect(self::MARGIN, $y, self::CONTENT_WIDTH, 42, '#FAFBFD', '#E3E8F2', 0.5);
            $pdf->text(self::MARGIN + 10, $y + 15, 'Nenhum registro no período.', 8.4, false, '#687589');
            return $y + 54;
        }
        $this->drawTableHeader($pdf, $y, $headers, $widths, $tone);
        $y += $headerHeight;
        foreach ($rows as $index => $row) {
            if ($y + $rowHeight > 788) {
                $pdf->addPage();
                $this->continuationHeader($pdf, $company, $period, $tone, $secondary);
                $y = 58;
                $this->drawTableHeader($pdf, $y, $headers, $widths, $tone);
                $y += $headerHeight;
            }
            $fill = $index % 2 === 0 ? '#FFFFFF' : '#FAFBFD';
            $pdf->rect(self::MARGIN, $y, self::CONTENT_WIDTH, $rowHeight, $fill, '#EDF1F6', 0.35);
            $x = self::MARGIN;
            foreach ($headers as $col => $_header) {
                $text = (string) ($row[$col] ?? '');
                $pdf->paragraph($x + 5, $y + 7, ((float) ($widths[$col] ?? 80)) - 10, $text, 7.2, 8.5, false, '#354156', 1);
                $x += (float) ($widths[$col] ?? 80);
            }
            $y += $rowHeight;
        }
        return $y + 14;
    }

    /** @param list<string> $headers @param list<int|float> $widths */
    private function drawTableHeader(SimplePdfDocument $pdf, float $y, array $headers, array $widths, string $tone): void
    {
        $pdf->rect(self::MARGIN, $y, self::CONTENT_WIDTH, 24, '#F0F5FF', '#DDE6F3', 0.5);
        $pdf->rect(self::MARGIN, $y, self::CONTENT_WIDTH, 2.5, $tone, null);
        $x = self::MARGIN;
        foreach ($headers as $index => $header) {
            $pdf->paragraph($x + 5, $y + 8, ((float) ($widths[$index] ?? 80)) - 10, $header, 6.7, 7.7, true, '#42526A', 1);
            $x += (float) ($widths[$index] ?? 80);
        }
    }

    private function ensureSpace(SimplePdfDocument $pdf, float &$y, float $needed, string $company, string $period, string $primary, string $secondary): void
    {
        if ($y + $needed <= 788) {
            return;
        }
        $pdf->addPage();
        $this->continuationHeader($pdf, $company, $period, $primary, $secondary);
        $y = 58;
    }

    private function duration(mixed $seconds): string
    {
        $seconds = max(0, (int) round((float) $seconds));
        if ($seconds <= 0) return 'Sem dados';
        if ($seconds < 60) return $seconds . 's';
        if ($seconds < 3600) return intdiv($seconds, 60) . 'min ' . ($seconds % 60) . 's';
        return intdiv($seconds, 3600) . 'h ' . intdiv($seconds % 3600, 60) . 'min';
    }

    private function number(mixed $value): string { return number_format((float) $value, 0, ',', '.'); }
    private function percent(mixed $value): string { return number_format((float) $value, 1, ',', '.') . '%'; }
    private function dateBr(string $date): string { $ts = strtotime($date); return $ts ? date('d/m/Y', $ts) : '-'; }
    private function upper(string $value): string { if (function_exists('mb_strtoupper')) return mb_strtoupper($value, 'UTF-8'); $value = strtr($value, ['á'=>'Á','à'=>'À','â'=>'Â','ã'=>'Ã','ä'=>'Ä','é'=>'É','è'=>'È','ê'=>'Ê','ë'=>'Ë','í'=>'Í','ì'=>'Ì','î'=>'Î','ï'=>'Ï','ó'=>'Ó','ò'=>'Ò','ô'=>'Ô','õ'=>'Õ','ö'=>'Ö','ú'=>'Ú','ù'=>'Ù','û'=>'Û','ü'=>'Ü','ç'=>'Ç']); return strtoupper($value); }
    private function color(string $candidate, string $fallback): string { return preg_match('/^#[0-9A-Fa-f]{6}$/', trim($candidate)) === 1 ? strtoupper(trim($candidate)) : $fallback; }
}
