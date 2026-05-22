<?php

declare(strict_types=1);

namespace App\EventListener\Regmel;

use App\Entity\Initiative;
use App\Enum\StatusProposalEnum;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Symfony\Bundle\SecurityBundle\Security;

class EvaluationEventListener
{
    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly Security $security,
    ) {
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $entity = $args->getObject();

        if (!$entity instanceof Initiative) {
            return;
        }

        $this->recordEvaluationAudit($entity);
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $entity = $args->getObject();

        if (!$entity instanceof Initiative) {
            return;
        }

        $this->recordEvaluationAudit($entity);
    }

    private function recordEvaluationAudit(Initiative $proposal): void
    {
        $extraFields = $proposal->getExtraFields() ?? [];

        // Registrar apenas se houver avaliação completa
        if (($extraFields['evaluation_status'] ?? null) !== 'completed') {
            return;
        }

        $user = $this->security->getUser();

        $timeline = [
            'resourceId' => $proposal->getId()->toRfc4122(),
            'priority' => 1,
            'datetime' => new \DateTime('now', new \DateTimeZone('America/Sao_Paulo')),
            'device' => 'Backend',
            'platform' => 'API',
            'action' => 'evaluation_completed',
            'details' => [
                'result' => $extraFields['evaluation_result'] ?? null,
                'reason' => $extraFields['evaluation_reason'] ?? null,
                'notes' => $extraFields['evaluation_notes'] ?? null,
                'ranking' => $extraFields['evaluation_ranking'] ?? null,
                'status' => $extraFields['status'] ?? null,
            ],
            'userId' => $user?->getId()?->toRfc4122() ?? 'system',
            'userName' => $user?->getFirstname() ?? 'Sistema',
        ];

        // Inserir auditoria no MongoDB
        try {
            $this->documentManager->createQueryBuilder(Initiative::class)
                ->updateOne()
                ->field('resourceId')->equals($proposal->getId()->toRfc4122())
                ->field('timeline')->push($timeline)
                ->getQuery()
                ->execute();
        } catch (\Exception $e) {
            // Log silencioso - não deve interromper o fluxo principal
        }
    }
}
