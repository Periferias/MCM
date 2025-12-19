<?php

declare(strict_types=1);

namespace App\Twig\Runtime;

use App\DocumentService\NotificationDocumentService;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\RuntimeExtensionInterface;

final readonly class NotificationRuntime implements RuntimeExtensionInterface
{
    public function __construct(
        private NotificationDocumentService $notificationService,
        private Security $security,
    ) {
    }

    public function getNavbarNotifications(): array
    {
        $user = $this->security->getUser();

        if (null === $user) {
            return [];
        }

        return $this->notificationService->findUnvisitedByTarget(
            $user->getId()->toRfc4122(),
            5 // Limita a 5 notificações na navbar
        );
    }

    public function getNavbarNotificationsCount(): int
    {
        $user = $this->security->getUser();

        if (null === $user) {
            return 0;
        }

        return $this->notificationService->countUnvisitedByTarget(
            $user->getId()->toRfc4122()
        );
    }
}
