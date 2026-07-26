<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Regra única do tempo de espera da IA.
 *
 * O valor configurado em ai_agents.cooldown_seconds passa a significar o
 * tempo mínimo de silêncio após a ÚLTIMA mensagem recebida antes de uma
 * resposta automática. Se outra mensagem chegar durante a espera, o relógio
 * reinicia. Também preserva a proteção contra duas respostas de IA muito
 * próximas usando a última saída automática como piso adicional.
 */
final class AiReplyTimingService
{

    public function remainingForConversation(PDO $pdo, int $conversationId, int $configuredSeconds): int
    {
        $configuredSeconds = max(0, min(3600, $configuredSeconds));
        if ($configuredSeconds === 0 || $conversationId < 1) {
            return 0;
        }

        $statement = $pdo->prepare(
            'SELECT
                (SELECT incoming.sent_at
                   FROM conversation_messages incoming
                  WHERE incoming.conversation_id = :conversation_id_in
                    AND incoming.direction = "incoming"
                  ORDER BY incoming.sent_at DESC, incoming.id DESC
                  LIMIT 1) AS last_incoming_at,
                (SELECT outgoing.sent_at
                   FROM conversation_messages outgoing
                  WHERE outgoing.conversation_id = :conversation_id_out
                    AND outgoing.direction = "outgoing"
                    AND outgoing.sender_type = "ai"
                    AND outgoing.status IN ("sent", "delivered", "read")
                  ORDER BY outgoing.sent_at DESC, outgoing.id DESC
                  LIMIT 1) AS last_ai_reply_at'
        );
        $statement->execute([
            'conversation_id_in' => $conversationId,
            'conversation_id_out' => $conversationId,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];

        return $this->remainingSeconds(
            $configuredSeconds,
            isset($row['last_incoming_at']) ? (string) $row['last_incoming_at'] : null,
            isset($row['last_ai_reply_at']) ? (string) $row['last_ai_reply_at'] : null
        );
    }

    public function remainingSeconds(
        int $configuredSeconds,
        ?string $lastIncomingAt,
        ?string $lastAiReplyAt,
        ?DateTimeImmutable $now = null
    ): int {
        $configuredSeconds = max(0, min(3600, $configuredSeconds));
        if ($configuredSeconds === 0) {
            return 0;
        }

        $nowTs = ($now ?? new DateTimeImmutable('now'))->getTimestamp();
        $remaining = 0;

        foreach ([$lastIncomingAt, $lastAiReplyAt] as $timestamp) {
            $timestamp = trim((string) $timestamp);
            if ($timestamp === '') {
                continue;
            }
            try {
                $eventTs = (new DateTimeImmutable($timestamp))->getTimestamp();
            } catch (Throwable) {
                $eventTs = strtotime($timestamp) ?: 0;
            }
            if ($eventTs <= 0) {
                continue;
            }
            $elapsed = max(0, $nowTs - $eventTs);
            $remaining = max($remaining, max(0, $configuredSeconds - $elapsed));
        }

        return $remaining;
    }
}
