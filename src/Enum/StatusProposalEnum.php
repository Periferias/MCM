<?php

declare(strict_types=1);

namespace App\Enum;

use App\Enum\Trait\EnumTrait;

enum StatusProposalEnum: string
{
    use EnumTrait;

    case ENVIADA = 'Enviada';
    case RECEBIDA = 'Recebida';
    case SEM_ADESAO = 'Sem Adesão do Município';
    case AGUARDANDO_AVALIACAO_ANUENCIA = 'Aguardando Validação da Anuência';
    case ANUIDA = 'Anuída pelo Município';
    case NAO_ANUIDA = 'Não Anuída pelo Município';
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

    public static function selectedValues(): array
    {
        return [
            self::SELECIONADA->value,
            self::SELECIONADA_DESEMPATE->value,
        ];
    }

    public static function classifiedValues(): array
    {
        return [
            self::CLASSIFICADA->value,
            self::CLASSIFICADA_CADASTRO_RESERVA_30->value,
            self::CLASSIFICADA_NAO_SELECIONADA_LIMITE_META_UF->value,
            self::CLASSIFICADA_NAO_SELECIONADA_DESEMPATE_NOTA->value,
            self::CLASSIFICADA_NAO_SELECIONADA_DESEMPATE_NATUREZA_JURIDICA->value,
            self::CLASSIFICADA_NAO_SELECIONADA_DESEMPATE_TEMPO_CNPJ->value,
            self::CLASSIFICADA_NAO_SELECIONADA_LIMITE_OSC->value,
        ];
    }

    public static function rankedValues(): array
    {
        return [
            ...self::selectedValues(),
            ...self::classifiedValues(),
        ];
    }

    public static function isSelected(string $status): bool
    {
        return in_array($status, self::selectedValues(), true);
    }

    public static function isClassified(string $status): bool
    {
        return in_array($status, self::classifiedValues(), true);
    }

    public static function isRanked(string $status): bool
    {
        return in_array($status, self::rankedValues(), true);
    }
}
