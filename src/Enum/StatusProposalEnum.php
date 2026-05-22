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
    case SELECIONADA_DESEMPATE = 'Selecionada por desempate';
    case NAO_SELECIONADA = 'Não Selecionada';
    case CLASSIFICADA = 'Classificada';
    case CLASSIFICADA_CADASTRO_RESERVA = 'Classificada em cadastro de reserva';
    case CLASSIFICADA_NAO_SELECIONADA_EMPATE = 'Classificada e não selecionada por empate';
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
            self::CLASSIFICADA_CADASTRO_RESERVA->value,
            self::CLASSIFICADA_NAO_SELECIONADA_EMPATE->value,
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
