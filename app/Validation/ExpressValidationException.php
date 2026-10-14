<?php

declare(strict_types=1);

namespace App\Validation;

use InvalidArgumentException;

final class ExpressValidationException extends InvalidArgumentException
{
    /** @param array<string,string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Payload da Configuração Expressa inválido.');
    }
}
