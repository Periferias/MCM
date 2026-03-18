<?php

declare(strict_types=1);

namespace App\Regmel\Enum;

enum EvaluationResultEnum: string
{
    case SELECIONADA = 'Selecionada';
    case NAO_SELECIONADA = 'Não Selecionada';
    case CLASSIFICADA = 'Classificada';
}
