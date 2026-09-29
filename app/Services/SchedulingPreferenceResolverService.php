<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Normaliza o que o contato quer mudar na agenda sem depender de um nicho.
 *
 * A camada conversa somente em conceitos operacionais da plataforma:
 * dia/data, horário/período e forma de atendimento. Psicologia, fisioterapia,
 * nutrição, estética ou qualquer outro segmento usam o mesmo contrato.
 */
final class SchedulingPreferenceResolverService
{
    /** @return array<string,mixed> */
    public function resolve(string $content, bool $continuationContext = false): array
    {
        $text = $this->normalize($content);
        $preferredDate = $this->extractDateText($text);
        $preferredDay = $this->extractDayText($text);
        $preferredTime = $this->extractTimeText($text);
        $modality = $this->extractModality($text);

        $asksOpeningHours = preg_match(
            '/\b(horario|horarios)\s+de\s+(atendimento|funcionamento|abertura|fechamento)\b|\b(qual|quais|que)\b.{0,20}\b(horario|horarios)\b.{0,20}\b(atendem|atendimento|funcionam|funcionamento)\b|\bque horas\b.{0,15}\b(abre|fecha|funciona|atende)\b/u',
            $text
        ) === 1;

        $availabilityInquiry = !$asksOpeningHours && preg_match(
            '/\b(qual|quais|tem|ha|existe|ver|consultar|mostrar|mostra|procuro|procurar)\b.{0,40}\b(horario|horarios|vaga|vagas|disponibilidade)\b|\b(horario|horarios|vaga|vagas)\b.{0,35}\b(disponivel|disponiveis|livre|livres)\b|\btem\s+vaga\b|\bha\s+vaga\b/u',
            $text
        ) === 1;

        $modalityChangeRequested = preg_match(
            '/\b(trocar|mudar|alterar|substituir)\b.{0,35}\b(modalidade|forma\s+de\s+atendimento|online|presencial)\b|\b(na\s+verdade|agora|em\s+vez)\b.{0,30}\b(online|presencial)\b/u',
            $text
        ) === 1;

        $preferenceCorrection = $modalityChangeRequested || preg_match(
            '/\b(na\s+verdade|melhor|prefiro|preferia|pode\s+ser|em\s+vez|trocar|mudar|alterar|outro\s+dia|outro\s+horario|outro\s+horário)\b/u',
            $text
        ) === 1;

        $directAgenda = !$asksOpeningHours && (
            preg_match(
                '/\b(agendar|reagendar|remarcar|desmarcar|encaixe)\b|\bmarcar\b.{0,30}\b(consulta|sessao|reuniao|horario|atendimento)\b|\b(quero|gostaria|preciso)\b.{0,30}\b(horario|horarios|vaga|vagas|agendar|marcar|disponibilidade)\b|\b(horario|horarios|vaga|vagas)\s+(disponivel|disponiveis|livre|livres)\b|\b(confirma|confirmar|confirmado)\b.{0,30}\b(horario|agendamento|consulta|atendimento)\b/u',
                $text
            ) === 1
            || $availabilityInquiry
            || $modalityChangeRequested
        );

        $hasPreference = $preferredDate !== '' || $preferredDay !== '' || $preferredTime !== '';
        $hasIntent = $directAgenda || ($continuationContext && ($hasPreference || $modality !== '' || $modalityChangeRequested));

        return [
            'has_intent' => $hasIntent,
            'preferred_date' => $preferredDate,
            'preferred_day' => $preferredDay,
            'preferred_time' => $preferredTime,
            'modality' => $modality,
            'location_type' => $modality === 'Presencial'
                ? 'presencial'
                : ($modality === 'Online' ? 'online' : ($modality === 'Telefone' ? 'telefone' : 'indefinida')),
            'availability_inquiry' => $availabilityInquiry,
            'modality_change_requested' => $modalityChangeRequested,
            'preference_correction' => $preferenceCorrection,
        ];
    }

    private function normalize(string $content): string
    {
        $text = mb_strtolower(trim($content));
        return strtr($text, [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a',
            'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ç' => 'c',
        ]);
    }

    private function extractDateText(string $text): string
    {
        if (preg_match('/\b(\d{1,2})[\/\-](\d{1,2})(?:[\/\-](\d{2,4}))?\b/u', $text, $match)) {
            $day = str_pad($match[1], 2, '0', STR_PAD_LEFT);
            $month = str_pad($match[2], 2, '0', STR_PAD_LEFT);
            $year = $match[3] ?? date('Y');
            if (strlen($year) === 2) {
                $year = '20' . $year;
            }
            return $year . '-' . $month . '-' . $day;
        }
        return '';
    }

    private function extractDayText(string $text): string
    {
        foreach ([
            'segunda-feira' => 'segunda-feira', 'segunda feira' => 'segunda-feira', 'segunda' => 'segunda-feira',
            'terca-feira' => 'terça-feira', 'terca feira' => 'terça-feira', 'terca' => 'terça-feira',
            'quarta-feira' => 'quarta-feira', 'quarta feira' => 'quarta-feira', 'quarta' => 'quarta-feira',
            'quinta-feira' => 'quinta-feira', 'quinta feira' => 'quinta-feira', 'quinta' => 'quinta-feira',
            'sexta-feira' => 'sexta-feira', 'sexta feira' => 'sexta-feira', 'sexta' => 'sexta-feira',
            'sabado' => 'sábado', 'domingo' => 'domingo',
        ] as $needle => $label) {
            if (str_contains($text, $needle)) {
                return $label;
            }
        }
        if (str_contains($text, 'amanha')) return 'amanhã';
        if (str_contains($text, 'hoje')) return 'hoje';
        return '';
    }

    private function extractTimeText(string $text): string
    {
        // Datas nunca podem virar horário.
        $timeText = preg_replace('/\b\d{1,2}[\/\-]\d{1,2}(?:[\/\-]\d{2,4})?\b/u', ' ', $text) ?? $text;

        // 14:30 é inequívoco.
        if (preg_match('/\b([01]?\d|2[0-3]):([0-5]\d)\b/u', $timeText, $match)) {
            return str_pad((string) (int) $match[1], 2, '0', STR_PAD_LEFT) . ':' . $match[2];
        }

        // 14h / 14 h / 14h30 são inequívocos. Números soltos (ex.: idade 20)
        // NÃO são interpretados como horário.
        if (preg_match('/\b([01]?\d|2[0-3])\s*h(?:\s*([0-5]\d))?\b/u', $timeText, $match)) {
            $minute = isset($match[2]) && $match[2] !== '' ? $match[2] : '00';
            return str_pad((string) (int) $match[1], 2, '0', STR_PAD_LEFT) . ':' . $minute;
        }

        // "às 14", "por volta das 14", "depois das 14".
        if (preg_match('/\b(?:as|a\s+partir\s+das|por\s+volta\s+das|depois\s+das|apos\s+as)\s+([01]?\d|2[0-3])\b/u', $timeText, $match)) {
            return str_pad((string) (int) $match[1], 2, '0', STR_PAD_LEFT) . ':00';
        }

        if (preg_match('/\b(manha|tarde|noite)\b/u', $text, $match)) {
            return match ($match[1]) {
                'manha' => 'manhã',
                'tarde' => 'tarde',
                'noite' => 'noite',
                default => '',
            };
        }
        return '';
    }

    private function extractModality(string $text): string
    {
        // Em correções explícitas, a modalidade depois de "para" é o destino.
        // Isso resolve frases com as duas palavras, como "trocar de presencial para online".
        if (preg_match('/\b(?:trocar|mudar|alterar|passar|ir)\b.{0,45}\bpara\s+(online|presencial)\b/u', $text, $match)
            || preg_match('/\bde\s+(?:online|presencial)\s+para\s+(online|presencial)\b/u', $text, $match)) {
            return ($match[1] ?? '') === 'online' ? 'Online' : 'Presencial';
        }
        if (preg_match('/\bem\s+vez\s+de\s+(?:online|presencial)\b.{0,30}\b(?:quero|prefiro|pode\s+ser)?\s*(online|presencial)\b/u', $text, $match)) {
            return ($match[1] ?? '') === 'online' ? 'Online' : 'Presencial';
        }
        if (preg_match('/\bpresencial\b|\bconsultorio\b|\bno\s+local\b/u', $text)) {
            return 'Presencial';
        }
        if (preg_match('/\btelefone\b|\bligacao\b|\bligar\b/u', $text)) {
            return 'Telefone';
        }
        if (preg_match('/\bonline\b|\bmeet\b|\bvideo\b|\bremoto\b|\bvirtual\b/u', $text)) {
            return 'Online';
        }
        return '';
    }
}
