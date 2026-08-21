<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Monta o contexto com orçamento explícito antes da chamada ao provedor.
 * Evita reenviar todo o histórico e toda a base de conhecimento em cada turno.
 */
final class AiContextBuilder
{
    /** @var array<string,bool> */
    private array $stopWords = [];

    public function __construct(private readonly AiEfficiencyPolicyService $policy = new AiEfficiencyPolicyService())
    {
        $words = 'a,o,as,os,um,uma,de,da,do,das,dos,e,em,no,na,nos,nas,para,por,com,sem,que,se,como,qual,quais,quando,onde,porque,porquê,eu,voce,você,me,meu,minha,nossa,nosso,isso,isto,essa,esse,esta,este,tem,ter,ser,esta,está,sao,são,mais,muito,muita,pode,poder,quero,gostaria';
        foreach (explode(',', $words) as $word) {
            $this->stopWords[$this->normalize($word)] = true;
        }
    }

    /**
     * @return array{messages:array<int,array<string,mixed>>,agent:array<string,mixed>,telemetry:array<string,mixed>}
     */
    public function build(PDO $pdo, array $agent, int $conversationId, string $incomingContent): array
    {
        $profile = $this->policy->profile($agent);
        $historyLimit = (int) $profile['history_limit'];
        $baselineHistoryLimit = max(4, min(30, (int) ($agent['max_context_messages'] ?? 12)));

        $aggregate = $pdo->prepare(
            'SELECT COUNT(*) AS total_messages
             FROM conversation_messages
             WHERE conversation_id = :conversation_id
               AND NOT (direction = "outgoing" AND status = "failed")'
        );
        $aggregate->execute(['conversation_id' => $conversationId]);
        $historyStats = $aggregate->fetch(PDO::FETCH_ASSOC) ?: [];

        // Busca no máximo o contexto que a versão anterior enviaria. A economia estimada
        // compara contra esse baseline real, e não contra toda a conversa histórica.
        $statement = $pdo->prepare(
            'SELECT * FROM (
                SELECT direction, sender_type, content, sent_at
                FROM conversation_messages
                WHERE conversation_id = :conversation_id
                  AND NOT (direction = "outgoing" AND status = "failed")
                ORDER BY sent_at DESC, id DESC
                LIMIT ' . $baselineHistoryLimit . '
             ) recent
             ORDER BY sent_at ASC'
        );
        $statement->execute(['conversation_id' => $conversationId]);
        $baselineMessages = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $messages = count($baselineMessages) > $historyLimit
            ? array_slice($baselineMessages, -$historyLimit)
            : $baselineMessages;

        $baselineHistoryChars = 0;
        foreach ($baselineMessages as $message) {
            $baselineHistoryChars += mb_strlen((string) ($message['content'] ?? ''));
        }
        $historySentChars = 0;
        foreach ($messages as $message) {
            $historySentChars += mb_strlen((string) ($message['content'] ?? ''));
        }

        $knowledge = trim((string) ($agent['knowledge_base'] ?? ''));
        $knowledgeTotalChars = mb_strlen($knowledge);
        $knowledgeSent = $knowledge;

        if ($profile['selective_knowledge'] && $knowledgeTotalChars > (int) $profile['knowledge_budget_chars']) {
            $queryParts = [$incomingContent];
            foreach (array_slice($messages, -3) as $message) {
                if ((string) ($message['direction'] ?? '') === 'incoming') {
                    $queryParts[] = (string) ($message['content'] ?? '');
                }
            }
            $knowledgeSent = $this->selectKnowledge(
                $knowledge,
                implode("\n", $queryParts),
                (int) $profile['knowledge_budget_chars']
            );
        }

        $knowledgeSentChars = mb_strlen($knowledgeSent);
        $removedChars = max(0, $baselineHistoryChars - $historySentChars)
            + max(0, $knowledgeTotalChars - $knowledgeSentChars);

        $preparedAgent = $agent;
        $preparedAgent['knowledge_base'] = $knowledgeSent;
        $preparedAgent['_ai_efficiency_mode'] = $profile['mode'];
        $preparedAgent['_ai_max_output_tokens'] = $profile['max_output_tokens'];

        return [
            'messages' => $messages,
            'agent' => $preparedAgent,
            'telemetry' => [
                'efficiency_mode' => $profile['mode'],
                'history_messages_total' => (int) ($historyStats['total_messages'] ?? 0),
                'history_messages_sent' => count($messages),
                'knowledge_chars_total' => $knowledgeTotalChars,
                'knowledge_chars_sent' => $knowledgeSentChars,
                'estimated_input_tokens_avoided' => (int) ceil($removedChars / 4),
            ],
        ];
    }

    private function selectKnowledge(string $knowledge, string $query, int $budget): string
    {
        $budget = max(1000, $budget);
        if (mb_strlen($knowledge) <= $budget) {
            return $knowledge;
        }

        $chunks = preg_split('/\n\s*\n+/u', $knowledge) ?: [];
        if (count($chunks) < 2) {
            $chunks = preg_split('/(?<=\.)\s+(?=[A-ZÁÉÍÓÚÃÕÇ])/u', $knowledge) ?: [$knowledge];
        }

        $queryTerms = $this->terms($query);
        $ranked = [];
        foreach ($chunks as $index => $chunk) {
            $chunk = trim((string) $chunk);
            if ($chunk === '') {
                continue;
            }
            $terms = $this->terms($chunk);
            $score = 0;
            foreach ($queryTerms as $term => $_) {
                if (isset($terms[$term])) {
                    $score += 4;
                }
            }
            if ($index < 2) {
                $score += 2; // mantém informações introdutórias da empresa como contexto-base.
            }
            $ranked[] = ['index' => $index, 'chunk' => $chunk, 'score' => $score];
        }

        usort($ranked, static function (array $a, array $b): int {
            $scoreOrder = ($b['score'] <=> $a['score']);
            return $scoreOrder !== 0 ? $scoreOrder : ($a['index'] <=> $b['index']);
        });

        $selected = [];
        $used = 0;
        foreach ($ranked as $item) {
            $chunk = (string) $item['chunk'];
            $size = mb_strlen($chunk) + 2;
            if ($used > 0 && $used + $size > $budget) {
                continue;
            }
            if ($used === 0 && $size > $budget) {
                $chunk = mb_substr($chunk, 0, $budget);
                $size = mb_strlen($chunk);
            }
            $selected[] = ['index' => (int) $item['index'], 'chunk' => $chunk];
            $used += $size;
            if ($used >= $budget) {
                break;
            }
        }

        if ($selected === []) {
            return mb_substr($knowledge, 0, $budget);
        }

        usort($selected, static fn (array $a, array $b): int => $a['index'] <=> $b['index']);
        return trim(implode("\n\n", array_column($selected, 'chunk')));
    }

    /** @return array<string,bool> */
    private function terms(string $text): array
    {
        $normalized = $this->normalize($text);
        $parts = preg_split('/\s+/u', $normalized) ?: [];
        $terms = [];
        foreach ($parts as $part) {
            if (mb_strlen($part) < 3 || isset($this->stopWords[$part])) {
                continue;
            }
            $terms[$part] = true;
        }
        return $terms;
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = strtr($value, [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a',
            'é' => 'e', 'ê' => 'e', 'í' => 'i',
            'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ü' => 'u', 'ç' => 'c',
        ]);
        return trim((string) preg_replace('/[^a-z0-9]+/u', ' ', $value));
    }
}
