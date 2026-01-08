<?php

declare(strict_types=1);

namespace App\Regmel\Repository;

use App\Regmel\Repository\Interface\ProposalRepositoryInterface;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

final class ProposalRepository implements ProposalRepositoryInterface
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * MODO LEITURA: Adicione este método ou ajuste o seu método de listagem.
     * Ele extrai os campos do JSON para que o Twig os veja como colunas normais.
     */
    public function findAllProposals(): array
    {
        $sql = <<<SQL
                SELECT 
                    i.id, 
                    i.name, 
                    i.image,
                    i.created_at,
                    i.updated_at,
                    i.created_by_id,
                    -- O operador ->> extrai o valor do JSON como TEXTO direto para o array
                    i.extra_fields->>'status' AS status,
                    i.extra_fields->>'city_name' AS city_name,
                    i.extra_fields->>'state' AS state,
                    i.extra_fields->>'status_updated_by_name' AS status_updated_by_name,
                    i.extra_fields->>'status_reason' AS status_reason
                FROM initiative i
                WHERE i.deleted_at IS NULL
                ORDER BY i.created_at DESC
            SQL;

        return $this->entityManager->getConnection()->fetchAllAssociative($sql);
    }

    /**
     * MODO ESCRITA: Atualiza o status e grava o nome de quem alterou.
     */
    public function bulkUpdateStatus(array $proposals, string $statusTo, string $userName): void
    {
        $sql = <<<SQL
                UPDATE initiative
                SET extra_fields = (COALESCE(extra_fields::jsonb, '{}'::jsonb) 
                    || jsonb_build_object(
                        'status', :statusTo::text,
                        'status_updated_by_name', :userName::text,
                        'status_updated_at', now()::text
                    ))::jsonb,
                    updated_at = now()
                WHERE id IN (:ids)
            SQL;

        $this->entityManager->getConnection()->executeStatement(
            $sql,
            [
                'statusTo' => $statusTo,
                'userName' => $userName,
                'ids' => $proposals,
            ],
            ['ids' => ArrayParameterType::STRING]
        );
    }
}
