<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\DocumentService\NotificationDocumentService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;

final readonly class NotificationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private NotificationDocumentService $notificationService,
        private Security $security,
        private Environment $twig,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => 'onKernelController',
        ];
    }

    public function onKernelController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->security->getUser();

        if (null === $user) {
            return;
        }

        // Busca apenas notificações não visitadas (mais eficiente)
        $unreadNotifications = $this->notificationService->findUnvisitedByTarget(
            $user->getId()->toRfc4122(),
            5 // Limita a 5 notificações na navbar
        );

        $unreadCount = $this->notificationService->countUnvisitedByTarget(
            $user->getId()->toRfc4122()
        );

        // Adiciona as notificações como variáveis globais do Twig
        $this->twig->addGlobal('navbarNotifications', $unreadNotifications);
        $this->twig->addGlobal('navbarNotificationsCount', $unreadCount);
    }
}
