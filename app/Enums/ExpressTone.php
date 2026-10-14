<?php

declare(strict_types=1);

namespace App\Enums;

enum ExpressTone: string
{
    case Empathetic = 'empathetic_and_welcoming';
    case Objective = 'objective';
    case Formal = 'formal';
    case Commercial = 'commercial';

    public function instruction(): string
    {
        return match ($this) {
            self::Empathetic => 'Acolhedor e empático, com perguntas naturais, uma por vez.',
            self::Objective => 'Objetivo, claro, cordial e sem excesso de informações.',
            self::Formal => 'Formal, respeitoso, preciso e profissional.',
            self::Commercial => 'Consultivo, prestativo e orientado a entender a necessidade, sem pressionar.',
        };
    }
}
