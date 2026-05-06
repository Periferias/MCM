<?php

declare(strict_types=1);

namespace App\Regmel\Enum;

enum EvaluationResultEnum: string
{
    case SELECIONADA = 'Selecionada';
    case SELECIONADA_DESEMPATE = 'Selecionada por Desempate';
    case NAO_SELECIONADA = 'Não Selecionada';
    case CLASSIFICADA = 'Classificada';
    case CLASSIFICADA_CADASTRO_RESERVA_30 = 'Classificada em Cadastro de Reserva 30%';
    case CLASSIFICADA_NAO_SELECIONADA_LIMITE_META_UF = 'Classificada e Não Selecionada por Limite de Meta UF';
    case CLASSIFICADA_NAO_SELECIONADA_DESEMPATE_NOTA = 'Classificada e Não Selecionada por Desempate de Nota';
    case CLASSIFICADA_NAO_SELECIONADA_DESEMPATE_NATUREZA_JURIDICA = 'Classificada e Não Selecionada por Desempate de Natureza Jurídica';
    case CLASSIFICADA_NAO_SELECIONADA_DESEMPATE_TEMPO_CNPJ = 'Classificada e Não Selecionada por Desempate de Tempo de CNPJ';
    case CLASSIFICADA_NAO_SELECIONADA_LIMITE_OSC = 'Classificada e Não Selecionada por Limite OSC';
    case DESCLASSIFICADA = 'Desclassificada';

    public function requiresRanking(): bool
    {
        return str_starts_with($this->value, 'Selecionada') || str_starts_with($this->value, 'Classificada');
    }
}
