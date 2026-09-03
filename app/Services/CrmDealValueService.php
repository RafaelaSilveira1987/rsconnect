<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use Throwable;

/**
 * Identifica valores comerciais tratados na conversa e sincroniza o valor
 * do negócio no CRM sem depender de uma nova chamada de IA.
 *
 * Regras conservadoras:
 * - um único valor monetário explícito pode preencher negócio ainda sem valor;
 * - quando há vários preços (ex.: tabela de planos), nenhum deles é escolhido;
 * - uma escolha/valor final explícito pode substituir o valor anterior;
 * - números de telefone, quantidades e percentuais não são tratados como preço.
 */
final class CrmDealValueService
{
    /** @return array{value:float,strong:bool,basis:string}|null */
    public function candidateFromText(string $content): ?array
    {
        $text = trim(preg_replace('/\s+/u', ' ', $content) ?? $content);
        if ($text === '') {
            return null;
        }

        $number = $this->numberPattern();
        $strongPatterns = [
            '~(?:valor\s+final|pre[cç]o\s+final|total(?:\s+fica)?|fechamos?\s+por|fechado\s+por|combinado\s+por|fica\s+por|mensalidade(?:\s+fica)?(?:\s+em|\s+de)?|proposta(?:\s+fica)?(?:\s+em|\s+de)?|contrato(?:\s+fica)?(?:\s+em|\s+de)?)[^0-9]{0,24}(?:R\$\s*)?(' . $number . ')~iu',
            '~(?:quero|prefiro|fico|vou\s+ficar|fecho|pode\s+ser)[^0-9]{0,36}(?:o\s+de|por)\s*(?:R\$\s*)?(' . $number . ')~iu',
            '~(?:recomendo|indico)[^.!?]{0,80}?\bpor\s+(?:R\$\s*)?(' . $number . ')~iu',
        ];

        $strongValues = [];
        foreach ($strongPatterns as $pattern) {
            if (preg_match_all($pattern, $text, $matches) !== false) {
                foreach ($matches[1] ?? [] as $raw) {
                    $value = $this->parseAmount((string) $raw);
                    if ($this->validValue($value)) {
                        $strongValues[] = $value;
                    }
                }
            }
        }

        $strongValues = $this->uniqueValues($strongValues);
        if (count($strongValues) === 1) {
            return ['value' => $strongValues[0], 'strong' => true, 'basis' => 'explicit_selection'];
        }
        if (count($strongValues) > 1) {
            return null;
        }

        $explicitValues = [];
        $explicitPatterns = [
            '~R\$\s*(' . $number . ')~iu',
            '~\b(' . $number . ')\s*(?:reais|real)\b~iu',
            '~\b(' . $number . ')\s*(?:/\s*m[eê]s|por\s+m[eê]s|mensais?)\b~iu',
        ];

        foreach ($explicitPatterns as $pattern) {
            if (preg_match_all($pattern, $text, $matches) !== false) {
                foreach ($matches[1] ?? [] as $raw) {
                    $value = $this->parseAmount((string) $raw);
                    if ($this->validValue($value)) {
                        $explicitValues[] = $value;
                    }
                }
            }
        }

        $explicitValues = $this->uniqueValues($explicitValues);
        if (count($explicitValues) !== 1) {
            return null;
        }

        return ['value' => $explicitValues[0], 'strong' => false, 'basis' => 'single_explicit_amount'];
    }

    /** @return array{lead_id:int,previous_value:float,value:float,updated:bool,strong:bool,basis:string}|null */
    public function captureForLead(PDO $pdo, int $tenantId, int $leadId, string $content, string $source = 'conversation'): ?array
    {
        if ($tenantId < 1 || $leadId < 1 || !$this->hasColumn($pdo, 'crm_leads', 'value')) {
            return null;
        }

        $candidate = $this->candidateFromText($content);
        if ($candidate === null) {
            return null;
        }

        try {
            $statement = $pdo->prepare(
                'SELECT id, contact_id, value, status
                 FROM crm_leads
                 WHERE id = :id AND tenant_id = :tenant_id
                 LIMIT 1'
            );
            $statement->execute(['id' => $leadId, 'tenant_id' => $tenantId]);
            $lead = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$lead || (string) ($lead['status'] ?? '') === 'lost') {
                return null;
            }

            $current = (float) ($lead['value'] ?? 0);
            $value = (float) $candidate['value'];
            $same = abs($current - $value) < 0.005;

            // Um valor já definido pode ter sido editado manualmente. Só uma
            // escolha/valor final explícito tem autoridade para substituí-lo.
            if ($current > 0 && empty($candidate['strong']) && !$same) {
                return [
                    'lead_id' => $leadId,
                    'previous_value' => $current,
                    'value' => $current,
                    'updated' => false,
                    'strong' => false,
                    'basis' => 'existing_value_preserved',
                ];
            }

            if (!$same) {
                $update = $pdo->prepare(
                    'UPDATE crm_leads
                     SET value = :value, updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id AND tenant_id = :tenant_id'
                );
                $update->execute([
                    'value' => number_format($value, 2, '.', ''),
                    'id' => $leadId,
                    'tenant_id' => $tenantId,
                ]);

                $this->addNote(
                    $pdo,
                    $tenantId,
                    (int) ($lead['contact_id'] ?? 0),
                    $leadId,
                    'Valor comercial identificado automaticamente na conversa: R$ '
                        . number_format($value, 2, ',', '.')
                        . ' (' . $this->sourceLabel($source) . ').'
                );
            }

            return [
                'lead_id' => $leadId,
                'previous_value' => $current,
                'value' => $value,
                'updated' => !$same,
                'strong' => !empty($candidate['strong']),
                'basis' => (string) $candidate['basis'],
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{lead_id:int,previous_value:float,value:float,updated:bool,strong:bool,basis:string}|null */
    public function captureFromConversation(PDO $pdo, int $tenantId, int $conversationId, string $content, string $source = 'ai'): ?array
    {
        if ($tenantId < 1 || $conversationId < 1) {
            return null;
        }

        try {
            $leadId = 0;
            if ($this->hasColumn($pdo, 'conversations', 'crm_lead_id')) {
                $statement = $pdo->prepare(
                    'SELECT crm_lead_id
                     FROM conversations
                     WHERE id = :conversation_id AND tenant_id = :tenant_id
                     LIMIT 1'
                );
                $statement->execute(['conversation_id' => $conversationId, 'tenant_id' => $tenantId]);
                $leadId = (int) ($statement->fetchColumn() ?: 0);
            }

            if ($leadId < 1 && $this->hasColumn($pdo, 'crm_leads', 'source_conversation_id')) {
                $statement = $pdo->prepare(
                    'SELECT id
                     FROM crm_leads
                     WHERE tenant_id = :tenant_id
                       AND source_conversation_id = :conversation_id
                       AND status <> "lost"
                     ORDER BY id DESC LIMIT 1'
                );
                $statement->execute(['tenant_id' => $tenantId, 'conversation_id' => $conversationId]);
                $leadId = (int) ($statement->fetchColumn() ?: 0);
            }

            return $leadId > 0
                ? $this->captureForLead($pdo, $tenantId, $leadId, $content, $source)
                : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function numberPattern(): string
    {
        return '(?:[0-9]{1,3}(?:[.\s][0-9]{3})+(?:,[0-9]{1,2})?|[0-9]+(?:[.,][0-9]{1,2})?)';
    }

    private function parseAmount(string $raw): float
    {
        $value = preg_replace('/\s+/u', '', trim($raw)) ?? trim($raw);
        if ($value === '') {
            return 0.0;
        }

        $hasComma = str_contains($value, ',');
        $hasDot = str_contains($value, '.');

        if ($hasComma && $hasDot) {
            // Formato BR: 1.234,56
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } elseif ($hasComma) {
            $parts = explode(',', $value);
            $decimal = end($parts);
            if ($decimal !== false && strlen($decimal) <= 2) {
                $value = str_replace('.', '', $value);
                $value = str_replace(',', '.', $value);
            } else {
                $value = str_replace(',', '', $value);
            }
        } elseif ($hasDot) {
            if (preg_match('/^[0-9]{1,3}(?:\.[0-9]{3})+$/', $value) === 1) {
                $value = str_replace('.', '', $value);
            }
        }

        return is_numeric($value) ? round((float) $value, 2) : 0.0;
    }

    private function validValue(float $value): bool
    {
        return $value > 0 && $value <= 99999999.99;
    }

    /** @param list<float> $values @return list<float> */
    private function uniqueValues(array $values): array
    {
        $unique = [];
        foreach ($values as $value) {
            $key = number_format($value, 2, '.', '');
            $unique[$key] = $value;
        }
        return array_values($unique);
    }

    private function addNote(PDO $pdo, int $tenantId, int $contactId, int $leadId, string $note): void
    {
        if ($contactId < 1 || !$this->hasTable($pdo, 'crm_notes')) {
            return;
        }
        try {
            $statement = $pdo->prepare(
                'INSERT INTO crm_notes (tenant_id, contact_id, lead_id, user_id, note)
                 VALUES (:tenant_id, :contact_id, :lead_id, NULL, :note)'
            );
            $statement->execute([
                'tenant_id' => $tenantId,
                'contact_id' => $contactId,
                'lead_id' => $leadId,
                'note' => $note,
            ]);
        } catch (Throwable) {
        }
    }

    private function sourceLabel(string $source): string
    {
        return match (strtolower(trim($source))) {
            'ai' => 'resposta da IA',
            'customer', 'cliente', 'incoming' => 'mensagem do cliente',
            default => 'conversa',
        };
    }

    private function hasTable(PDO $pdo, string $table): bool
    {
        try {
            $statement = $pdo->prepare(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
            );
            $statement->execute(['table' => $table]);
            return (int) $statement->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    private function hasColumn(PDO $pdo, string $table, string $column): bool
    {
        try {
            $statement = $pdo->prepare(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
            );
            $statement->execute(['table' => $table, 'column' => $column]);
            return (int) $statement->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }
}
