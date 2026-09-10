<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use Throwable;

/**
 * Reconstrói o turno atual do cliente: todas as mensagens recebidas depois da
 * última resposta enviada. É a mesma unidade que o humano lê como "uma fala"
 * quando o cliente envia 2 ou 3 balões em sequência no WhatsApp.
 */
final class AiTurnContextService
{
    /**
     * @return array{content:string,messages:list<array{id:int,content:string,sent_at:string}>,message_ids:list<int>,count:int}
     */
    public function currentTurn(PDO $pdo, int $conversationId, int $limit = 8, int $maxChars = 2200): array
    {
        $limit = max(1, min(20, $limit));
        $maxChars = max(400, min(6000, $maxChars));
        if ($conversationId < 1) {
            return ['content' => '', 'messages' => [], 'message_ids' => [], 'count' => 0];
        }

        try {
            // Busca as mensagens mais recentes do turno e, depois, restaura a
            // ordem cronológica. Assim uma rajada longa não faz o contexto ficar
            // preso nos primeiros balões e ignorar a pergunta mais nova.
            $statement = $pdo->prepare(
                'SELECT id, content, sent_at FROM (\n'
                . '  SELECT id, content, sent_at\n'
                . '  FROM conversation_messages\n'
                . '  WHERE conversation_id = :conversation_id\n'
                . '    AND direction = "incoming"\n'
                . '    AND message_type = "text"\n'
                . '    AND id > COALESCE((\n'
                . '        SELECT MAX(o.id)\n'
                . '        FROM conversation_messages o\n'
                . '        WHERE o.conversation_id = :conversation_id_out\n'
                . '          AND o.direction = "outgoing"\n'
                . '    ), 0)\n'
                . '  ORDER BY id DESC\n'
                . '  LIMIT ' . $limit . '\n'
                . ') current_turn ORDER BY id ASC'
            );
            $statement->execute([
                'conversation_id' => $conversationId,
                'conversation_id_out' => $conversationId,
            ]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return ['content' => '', 'messages' => [], 'message_ids' => [], 'count' => 0];
        }

        $messages = [];
        $ids = [];
        $parts = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $content = trim((string) ($row['content'] ?? ''));
            if ($id < 1 || $content === '') {
                continue;
            }
            $ids[] = $id;
            $parts[] = $content;
            $messages[] = [
                'id' => $id,
                'content' => $content,
                'sent_at' => (string) ($row['sent_at'] ?? ''),
            ];
        }

        $content = trim(implode("\n", $parts));
        if (mb_strlen($content) > $maxChars) {
            $content = mb_substr($content, -$maxChars);
        }

        return [
            'content' => $content,
            'messages' => $messages,
            'message_ids' => $ids,
            'count' => count($messages),
        ];
    }
}
