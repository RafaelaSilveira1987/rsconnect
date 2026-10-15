<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Forma reduzida do expediente para a Ativação Assistida.
 * A tela simplificada nunca deve converter múltiplos intervalos em 08:00-18:00.
 */
final class BusinessHoursCompactEditorService
{
    /** @return array{start:string,end:string,days:list<string>,complex:bool} */
    public static function describe(mixed $raw): array
    {
        $hours = BusinessHoursNormalizer::normalize($raw);
        if ($hours === null || $hours === []) {
            return ['start'=>'08:00','end'=>'18:00','days'=>['mon','tue','wed','thu','fri'],'complex'=>false];
        }
        $start = null;
        $end = null;
        $complex = false;
        foreach ($hours as $ranges) {
            if (count($ranges) !== 1) $complex = true;
            foreach ($ranges as [$from,$to]) {
                if ($start === null) { $start = $from; $end = $to; }
                elseif ($start !== $from || $end !== $to) $complex = true;
            }
        }
        return ['start'=>$start ?? '08:00','end'=>$end ?? '18:00',
            'days'=>array_keys($hours),'complex'=>$complex];
    }
}
