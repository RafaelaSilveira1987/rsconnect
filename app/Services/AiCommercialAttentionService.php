<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Monta uma lista simples de clientes que precisam de atenção comercial.
 *
 * A tela não altera plano, preço ou orçamento automaticamente. Ela apenas reúne
 * sinais já existentes e explica, em linguagem simples, por que o cliente merece
 * revisão e qual é o próximo passo mais seguro.
 */
final class AiCommercialAttentionService
{
    public const TRACKING_STATUSES = ['open', 'reviewing', 'waiting', 'resolved'];

    /** @return array<string,mixed> */
    public function dashboard(string $filter = 'active', string $search = ''): array
    {
        $filter = in_array($filter, ['active', 'urgent', 'week', 'monitor', 'resolved', 'all'], true) ? $filter : 'active';
        $search = trim($search);
        $commercialRows = (new AiCommercialMarginService())->overview();
        $historyService = new AiProfitabilityHistoryService();
        $budgetService = new AiBudgetPolicyService();
        $tracking = $this->trackingMap();
        $rows = [];

        foreach ($commercialRows as $commercial) {
            $tenantId = (int) ($commercial['tenant_id'] ?? 0);
            if ($tenantId < 1) {
                continue;
            }

            $history = $historyService->history($tenantId, 3);
            $simulation = $historyService->planSimulation($tenantId);
            $budget = $budgetService->decision($tenantId);
            $signal = $this->evaluate($commercial, $history, $simulation, $budget);
            $wasTracked = isset($tracking[$tenantId]);
            $saved = $tracking[$tenantId] ?? $this->defaultTracking($tenantId);
            $savedStatus = (string) ($saved['status'] ?? 'open');
            $savedHash = (string) ($saved['signal_hash'] ?? '');
            $currentHash = (string) ($signal['signal_hash'] ?? '');
            $hasSignals = !empty($signal['reasons']);
            $reopened = $savedStatus === 'resolved' && $hasSignals && $savedHash !== '' && !hash_equals($savedHash, $currentHash);

            if (!$hasSignals) {
                $effectiveStatus = $wasTracked ? 'resolved' : 'none';
            } elseif ($reopened) {
                $effectiveStatus = 'open';
            } else {
                $effectiveStatus = in_array($savedStatus, self::TRACKING_STATUSES, true) ? $savedStatus : 'open';
            }

            $row = array_merge($signal, [
                'tenant_id' => $tenantId,
                'tenant_name' => (string) ($commercial['tenant_name'] ?? ('Empresa #' . $tenantId)),
                'commercial' => $commercial,
                'history' => $history,
                'simulation' => $simulation,
                'budget' => $budget,
                'tracking_status' => $effectiveStatus,
                'tracking_note' => (string) ($saved['note'] ?? ''),
                'due_at' => $saved['due_at'] ?? null,
                'reopened' => $reopened,
                'updated_at' => $saved['updated_at'] ?? null,
                'tracked' => $wasTracked,
            ]);
            $rows[] = $row;
        }

        usort($rows, static function (array $a, array $b): int {
            $resolvedA = ($a['tracking_status'] ?? '') === 'resolved' ? 1 : 0;
            $resolvedB = ($b['tracking_status'] ?? '') === 'resolved' ? 1 : 0;
            if ($resolvedA !== $resolvedB) return $resolvedA <=> $resolvedB;
            $scoreCompare = (int) ($b['score'] ?? 0) <=> (int) ($a['score'] ?? 0);
            if ($scoreCompare !== 0) return $scoreCompare;
            return (float) ($b['commercial']['revenue_brl'] ?? 0) <=> (float) ($a['commercial']['revenue_brl'] ?? 0);
        });

        $summary = [
            'urgent' => 0,
            'week' => 0,
            'monitor' => 0,
            'resolved' => 0,
            'active' => 0,
            'revenue_at_risk_brl' => 0.0,
        ];
        foreach ($rows as $row) {
            if (($row['tracking_status'] ?? '') === 'resolved') {
                if (!empty($row['tracked'])) $summary['resolved']++;
                continue;
            }
            if (empty($row['reasons'])) continue;
            $summary['active']++;
            $priority = (string) ($row['priority'] ?? 'monitor');
            if ($priority === 'urgent') $summary['urgent']++;
            elseif ($priority === 'high') $summary['week']++;
            else $summary['monitor']++;
            $summary['revenue_at_risk_brl'] += max(0.0, (float) ($row['commercial']['revenue_brl'] ?? 0));
        }
        $summary['revenue_at_risk_brl'] = round((float) $summary['revenue_at_risk_brl'], 2);

        $filtered = array_values(array_filter($rows, function (array $row) use ($filter, $search): bool {
            $name = $this->lower((string) ($row['tenant_name'] ?? ''));
            if ($search !== '' && !str_contains($name, $this->lower($search))) return false;
            $status = (string) ($row['tracking_status'] ?? 'open');
            $priority = (string) ($row['priority'] ?? 'monitor');
            $hasSignals = !empty($row['reasons']);
            return match ($filter) {
                'urgent' => $status !== 'resolved' && $priority === 'urgent',
                'week' => $status !== 'resolved' && $priority === 'high',
                'monitor' => $status !== 'resolved' && in_array($priority, ['review', 'monitor'], true),
                'resolved' => $status === 'resolved' && !empty($row['tracked']),
                'all' => $hasSignals || !empty($row['tracked']),
                default => $status !== 'resolved' && $hasSignals,
            };
        }));

        return [
            'filter' => $filter,
            'search' => $search,
            'summary' => $summary,
            'rows' => $filtered,
            'all_rows' => $rows,
            'generated_at' => date('Y-m-d H:i:s'),
        ];
    }

    /** @param array<string,mixed> $data */
    public function saveTracking(int $tenantId, array $data, ?int $userId): void
    {
        if ($tenantId < 1) throw new RuntimeException('Selecione uma empresa válida.');
        $status = strtolower(trim((string) ($data['status'] ?? 'open')));
        if (!in_array($status, self::TRACKING_STATUSES, true)) $status = 'open';
        $note = trim((string) ($data['note'] ?? ''));
        $noteLength = function_exists('mb_strlen') ? mb_strlen($note) : strlen($note);
        if ($noteLength > 2000) $note = function_exists('mb_substr') ? mb_substr($note, 0, 2000) : substr($note, 0, 2000);
        $dueAt = trim((string) ($data['due_at'] ?? ''));
        if ($dueAt !== '' && DateTimeImmutable::createFromFormat('Y-m-d', $dueAt) === false) {
            throw new RuntimeException('Informe uma data válida para o próximo acompanhamento.');
        }

        $current = $this->tenantSignal($tenantId);
        $hash = (string) ($current['signal_hash'] ?? hash('sha256', 'no-signal'));
        $pdo = Database::connection();
        $check = $pdo->prepare('SELECT id FROM tenants WHERE id = :id LIMIT 1');
        $check->execute(['id' => $tenantId]);
        if (!$check->fetchColumn()) throw new RuntimeException('Empresa não encontrada.');

        $pdo->prepare(
            'INSERT INTO tenant_ai_commercial_attention_tracking
                (tenant_id, status, signal_hash, note, due_at, reviewed_at, resolved_at, updated_by_user_id)
             VALUES
                (:tenant_id, :status, :signal_hash, :note, :due_at,
                 IF(:status_reviewing = "reviewing", NOW(), NULL),
                 IF(:status_resolved = "resolved", NOW(), NULL), :updated_by)
             ON DUPLICATE KEY UPDATE
                status = VALUES(status), signal_hash = VALUES(signal_hash), note = VALUES(note), due_at = VALUES(due_at),
                reviewed_at = IF(VALUES(status) = "reviewing", COALESCE(reviewed_at, NOW()), reviewed_at),
                resolved_at = IF(VALUES(status) = "resolved", NOW(), NULL),
                updated_by_user_id = VALUES(updated_by_user_id)'
        )->execute([
            'tenant_id' => $tenantId,
            'status' => $status,
            'signal_hash' => $hash,
            'note' => $note !== '' ? $note : null,
            'due_at' => $dueAt !== '' ? $dueAt : null,
            'status_reviewing' => $status,
            'status_resolved' => $status,
            'updated_by' => $userId && $userId > 0 ? $userId : null,
        ]);
    }

    /** @return array<string,mixed> */
    public function tenantSignal(int $tenantId): array
    {
        foreach ((new AiCommercialMarginService())->overview() as $commercial) {
            if ((int) ($commercial['tenant_id'] ?? 0) !== $tenantId) continue;
            $history = (new AiProfitabilityHistoryService())->history($tenantId, 3);
            $simulation = (new AiProfitabilityHistoryService())->planSimulation($tenantId);
            $budget = (new AiBudgetPolicyService())->decision($tenantId);
            return $this->evaluate($commercial, $history, $simulation, $budget);
        }
        return ['signal_hash' => hash('sha256', 'tenant-not-found'), 'reasons' => []];
    }

    /** @param array<string,mixed> $commercial @param array<int,array<string,mixed>> $history @param array<string,mixed> $simulation @param array<string,mixed> $budget @return array<string,mixed> */
    private function evaluate(array $commercial, array $history, array $simulation, array $budget): array
    {
        $reasons = [];
        $status = (string) ($commercial['status'] ?? 'unconfigured');
        $revenue = max(0.0, (float) ($commercial['revenue_brl'] ?? 0));
        $margin = $commercial['projected_margin_rate'] ?? null;
        $target = (float) ($commercial['target_margin_rate'] ?? .60);
        $priceGap = max(0.0, (float) ($commercial['price_gap_brl'] ?? 0));
        $aiShare = max(0.0, (float) ($commercial['ai_cost_share_rate'] ?? 0));
        $avoidanceRate = max(0.0, (float) ($simulation['avoidance_rate'] ?? 0));

        if (empty($commercial['configured']) || $status === 'unconfigured') {
            $reasons[] = $this->reason('missing_data', 78, 'Faltam informações para calcular o resultado', 'Revise o valor mensal, a cotação do dólar ou os custos informados.');
        }
        if ($status === 'loss') {
            $reasons[] = $this->reason('known_loss', 100, 'Os custos informados podem superar o valor recebido', 'Confira os custos e reveja o valor mensal antes de manter a condição atual.');
        } elseif ($status === 'critical') {
            $reasons[] = $this->reason('low_margin', 88, 'O valor que sobra está muito baixo', 'O resultado ficou abaixo do limite de segurança definido para esta empresa.');
        } elseif ($status === 'attention') {
            $reasons[] = $this->reason('below_target', 66, 'O resultado está abaixo da meta', 'O valor mensal ainda cobre os custos, mas não alcança o percentual desejado.');
        }
        if ($priceGap >= max(5.0, $revenue * .03)) {
            $reasons[] = $this->reason('price_gap', 72, 'O valor mensal pode estar abaixo do necessário', 'Faltam aproximadamente R$ ' . number_format($priceGap, 2, ',', '.') . ' para alcançar a meta definida.');
        }

        $usedPercent = max(0.0, (float) ($budget['used_percent'] ?? 0));
        $hard = max(1.0, (float) ($budget['hard_limit_percent'] ?? 100));
        $critical = max(1.0, (float) ($budget['critical_percent'] ?? 95));
        $warning = max(1.0, (float) ($budget['warning_percent'] ?? 80));
        if (!empty($budget['blocked'])) {
            $reasons[] = $this->reason('budget_blocked', 100, 'O limite de gasto da IA foi atingido', 'Novas chamadas de IA pagas pela RS estão bloqueadas para proteger o orçamento.');
        } elseif (!empty($budget['enabled']) && $usedPercent >= $critical) {
            $reasons[] = $this->reason('budget_critical', 90, 'O gasto com IA está perto do limite', 'A empresa já utilizou ' . number_format($usedPercent, 1, ',', '.') . '% do limite configurado.');
        } elseif (!empty($budget['enabled']) && $usedPercent >= $warning) {
            $reasons[] = $this->reason('budget_warning', 62, 'O gasto com IA merece acompanhamento', 'A empresa já utilizou ' . number_format($usedPercent, 1, ',', '.') . '% do limite configurado.');
        }

        $projectedUsd = max(0.0, (float) ($commercial['projected_ai_cost_usd'] ?? 0));
        $budgetUsd = max(0.0, (float) ($budget['budget_usd'] ?? 0));
        $projectedBudgetPercent = $budgetUsd > 0 ? ($projectedUsd / $budgetUsd) * 100 : 0.0;
        if (!empty($budget['enabled']) && $usedPercent < $hard && $projectedBudgetPercent >= $hard) {
            $reasons[] = $this->reason('budget_forecast', 82, 'O gasto pode ultrapassar o limite até o fim do mês', 'Mantendo o ritmo atual, a previsão chega a ' . number_format($projectedBudgetPercent, 1, ',', '.') . '% do limite.');
        }

        $currentPlanKey = (string) ($simulation['current_plan_key'] ?? '');
        foreach ((array) ($simulation['plans'] ?? []) as $plan) {
            if ((string) ($plan['plan_key'] ?? '') !== $currentPlanKey) continue;
            if (empty($plan['capacity_ok'])) {
                $issues = array_map('strval', (array) ($plan['capacity_issues'] ?? []));
                $detail = $issues !== [] ? implode('; ', array_slice($issues, 0, 2)) : 'O uso atual ultrapassa um ou mais limites do plano.';
                $reasons[] = $this->reason('plan_capacity', 84, 'O plano atual pode não comportar o uso', $detail);
            }
            break;
        }

        if ($aiShare >= .20 && $avoidanceRate < .15) {
            $reasons[] = $this->reason('optimize_ai', 68, 'A IA pesa bastante no valor mensal', 'Antes de reajustar, vale reduzir chamadas repetidas e aumentar respostas reaproveitadas.');
        }

        $margins = [];
        foreach (array_slice($history, -2) as $item) {
            if (($item['margin_rate'] ?? null) !== null) $margins[] = (float) $item['margin_rate'];
        }
        if ($margin !== null) $margins[] = (float) $margin;
        if (count($margins) >= 3) {
            $a = $margins[count($margins) - 3];
            $b = $margins[count($margins) - 2];
            $c = $margins[count($margins) - 1];
            if ($b < $a - .01 && $c < $b - .01) {
                $reasons[] = $this->reason('margin_falling', 76, 'O resultado piorou nos últimos meses', 'O percentual que sobra caiu por dois períodos seguidos.');
            }
        }

        $previous = count($history) >= 2 ? $history[count($history) - 2] : [];
        $previousAi = max(0.0, (float) ($previous['ai_cost_brl'] ?? 0));
        $currentProjectedAi = max(0.0, (float) ($commercial['projected_ai_cost_brl'] ?? 0));
        if ($previousAi >= 1.0 && $currentProjectedAi >= $previousAi * 1.30 && ($currentProjectedAi - $previousAi) >= 2.0) {
            $increase = (($currentProjectedAi / $previousAi) - 1) * 100;
            $reasons[] = $this->reason('ai_cost_rising', 58, 'O custo da IA aumentou', 'A previsão está cerca de ' . number_format($increase, 0, ',', '.') . '% acima do mês anterior.');
        }

        usort($reasons, static fn (array $a, array $b): int => (int) ($b['weight'] ?? 0) <=> (int) ($a['weight'] ?? 0));
        $baseScore = $reasons !== [] ? (int) ($reasons[0]['weight'] ?? 0) : 0;
        $score = min(100, $baseScore + max(0, min(12, (count($reasons) - 1) * 3)));
        $priority = $score >= 90 ? 'urgent' : ($score >= 70 ? 'high' : ($score >= 45 ? 'review' : ($score > 0 ? 'monitor' : 'ok')));
        $action = $this->recommendedAction($reasons, (string) ($simulation['recommendation'] ?? 'configure'));
        $reasonCodes = array_values(array_map(static fn (array $reason): string => (string) ($reason['code'] ?? ''), $reasons));
        sort($reasonCodes);
        $signalHash = hash('sha256', implode('|', $reasonCodes) . '|' . $action['code'] . '|' . $priority);

        return [
            'score' => $score,
            'priority' => $priority,
            'reasons' => $reasons,
            'recommended_action' => $action,
            'signal_hash' => $signalHash,
            'projected_budget_percent' => $projectedBudgetPercent,
            'target_margin_rate' => $target,
        ];
    }

    /** @return array{code:string,weight:int,title:string,detail:string} */
    private function reason(string $code, int $weight, string $title, string $detail): array
    {
        return compact('code', 'weight', 'title', 'detail');
    }

    /** @param array<int,array<string,mixed>> $reasons @return array{code:string,title:string,description:string} */
    private function recommendedAction(array $reasons, string $simulationRecommendation): array
    {
        $codes = array_map(static fn (array $item): string => (string) ($item['code'] ?? ''), $reasons);
        if (in_array('missing_data', $codes, true)) return ['code' => 'complete_data', 'title' => 'Completar os dados', 'description' => 'Confira o valor mensal, os custos e a cotação para liberar uma análise confiável.'];
        if (in_array('budget_blocked', $codes, true) || in_array('budget_critical', $codes, true) || in_array('budget_forecast', $codes, true)) return ['code' => 'review_budget', 'title' => 'Rever o limite de gasto', 'description' => 'Confira o orçamento e reduza o uso da IA antes que o atendimento seja limitado.'];
        if (in_array('plan_capacity', $codes, true)) return ['code' => 'review_plan', 'title' => 'Rever o plano', 'description' => 'Compare um plano que comporte o uso atual e ainda mantenha um resultado saudável.'];
        if (in_array('optimize_ai', $codes, true) || $simulationRecommendation === 'optimize_first') return ['code' => 'reduce_ai', 'title' => 'Reduzir o gasto com IA', 'description' => 'Aumente respostas reaproveitadas, reduza contexto e revise chamadas repetidas antes de reajustar o cliente.'];
        if (array_intersect(['known_loss', 'low_margin', 'below_target', 'price_gap', 'margin_falling'], $codes)) return ['code' => 'review_price', 'title' => 'Rever o valor mensal', 'description' => 'Confira os custos e use o valor mínimo sugerido como apoio para uma possível revisão comercial.'];
        return ['code' => 'monitor', 'title' => 'Acompanhar', 'description' => 'Continue observando o custo, o limite de gasto e o percentual que sobra.'];
    }

    /** @return array<int,array<string,mixed>> */
    private function trackingMap(): array
    {
        try {
            $rows = Database::connection()->query('SELECT * FROM tenant_ai_commercial_attention_tracking')->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $map = [];
            foreach ($rows as $row) $map[(int) ($row['tenant_id'] ?? 0)] = $row;
            return $map;
        } catch (Throwable) {
            return [];
        }
    }

    private function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
    }

    /** @return array<string,mixed> */
    private function defaultTracking(int $tenantId): array
    {
        return ['tenant_id' => $tenantId, 'status' => 'open', 'signal_hash' => '', 'note' => '', 'due_at' => null, 'updated_at' => null];
    }
}
