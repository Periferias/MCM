<?php

declare(strict_types=1);

namespace App\DocumentService;

use App\Document\NotificationDocument;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Repository\DocumentRepository;

final class NotificationDocumentService
{
    private DocumentRepository $documentRepository;

    public function __construct(
        private readonly DocumentManager $documentManager
    ) {
        $this->documentRepository = $this->documentManager->getRepository(NotificationDocument::class);
    }

    /**
     * Busca notificações para um usuário específico (target)
     * Ordena por data de criação (mais recentes primeiro)
     * Limita a quantidade de notificações retornadas
     */
    public function findByTarget(string $targetUserId, int $limit = 10): array
    {
        return $this->documentRepository->findBy(
            ['target' => $targetUserId],
            ['createdAt' => 'DESC'],
            $limit
        );
    }

    /**
     * Busca notificações não visitadas para um usuário específico
     */
    public function findUnvisitedByTarget(string $targetUserId, int $limit = 10): array
    {
        return $this->documentRepository->findBy(
            [
                'target' => $targetUserId,
                'visited' => false,
            ],
            ['createdAt' => 'DESC'],
            $limit
        );
    }

    /**
     * Conta o número de notificações não visitadas de um usuário
     */
    public function countUnvisitedByTarget(string $targetUserId): int
    {
        return $this->documentRepository->count([
            'target' => $targetUserId,
            'visited' => false,
        ]);
    }

    /**
     * Marca uma notificação como visitada
     */
    public function markAsVisited(string $notificationId): void
    {
        $notification = $this->documentRepository->find($notificationId);

        if (null !== $notification) {
            $notification->markAsVisited();
            $this->documentManager->flush();
        }
    }

    /**
     * Marca todas as notificações de um usuário como visitadas
     */
    public function markAllAsVisitedByTarget(string $targetUserId): void
    {
        $notifications = $this->documentRepository->findBy([
            'target' => $targetUserId,
            'visited' => false,
        ]);

        foreach ($notifications as $notification) {
            $notification->markAsVisited();
        }

        $this->documentManager->flush();
    }
}
