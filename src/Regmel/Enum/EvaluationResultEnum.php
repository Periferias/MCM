<?php

declare(strict_types=1);

namespace App\Regmel\Enum;

enum EvaluationResultEnum: string
{
    case SELECIONADA = 'Selecionada';
    case SELECIONADA_DESEMPATE = 'Selecionada por desempate';
    case CLASSIFICADA_CADASTRO_RESERVA = 'Classificada em cadastro de reserva';
    case CLASSIFICADA_NAO_SELECIONADA_EMPATE = 'Classificada e não selecionada por empate';
    case DESCLASSIFICADA = 'Desclassificada';

    public function requiresRanking(): bool
    {
        return str_starts_with($this->value, 'Selecionada') || str_starts_with($this->value, 'Classificada');
    }
}
