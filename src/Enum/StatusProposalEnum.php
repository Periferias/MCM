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
    case NAO_SELECIONADA = 'Não Selecionada';
    case CLASSIFICADA = 'Classificada';
}
