<?php

declare(strict_types=1);

namespace App\EventListener\Regmel;

use App\Document\ProposalOrderingTimeline;
use App\Event\Regmel\ProposalOrderingEvent;
use DateTime;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;

#[AsEventListener(event: ProposalOrderingEvent::class, priority: 4096)]
class ProposalOrderingEventListener
{
    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly RequestStack $requestStack,
        private readonly Security $security,
    ) {
    }

    public function __invoke(ProposalOrderingEvent $event): void
    {
        $user = $this->security->getUser();
        $userId = $user?->getId()->toRfc4122() ?? '';
        $userName = $user?->getName() ?? 'System';

        // Calcular mudanças entre anterior e novo
        $changes = $this->calculateChanges($event->previousOrdering, $event->newOrdering);

        $document = new ProposalOrderingTimeline();
        $document->setProposalId($event->proposalId);
        $document->setUserId($userId);
        $document->setUserName($userName);
        $document->setTimestamp(new DateTime());
        $document->setPreviousOrdering($event->previousOrdering);
        $document->setNewOrdering($event->newOrdering);
        $document->setChanges($changes);
        $document->setDevice($this->getDevice());
        $document->setPlatform($this->getPlatform());

        $this->documentManager->persist($document);
        $this->documentManager->flush();
    }

    private function calculateChanges(array $previous, array $new): array
    {
        $changes = [];

        foreach ($new as $newItem) {
            $newId = $newItem['id'] ?? $newItem['proposalId'] ?? null;
            $newOrder = $newItem['order'] ?? $newItem['newOrder'] ?? null;

            if (!$newId) {
                continue;
            }

            // Encontrar item anterior
            $previousOrder = null;
            foreach ($previous as $prevItem) {
                $prevId = $prevItem['id'] ?? $prevItem['proposalId'] ?? null;
                if ($prevId === $newId) {
                    $previousOrder = $prevItem['order'] ?? $prevItem['newOrder'] ?? null;
                    break;
                }
            }

            // Se ordem mudou, registrar mudança
            if ($previousOrder !== $newOrder) {
                $changes[] = [
                    'proposalId' => $newId,
                    'from' => $previousOrder,
                    'to' => $newOrder,
                ];
            }
        }

        return $changes;
    }

    private function getDevice(): string
    {
        $request = $this->requestStack->getCurrentRequest();
        if (!$request) {
            return 'unknown';
        }

        $userAgent = $request->headers->get('User-Agent', '');

        if (stripos($userAgent, 'Mobile') !== false) {
            return 'mobile';
        } elseif (stripos($userAgent, 'Tablet') !== false) {
            return 'tablet';
        }

        return 'desktop';
    }

    private function getPlatform(): string
    {
        $request = $this->requestStack->getCurrentRequest();
        if (!$request) {
            return 'unknown';
        }

        $userAgent = $request->headers->get('User-Agent', '');

        if (stripos($userAgent, 'Windows') !== false) {
            return 'Windows';
        } elseif (stripos($userAgent, 'Mac') !== false) {
            return 'macOS';
        } elseif (stripos($userAgent, 'Linux') !== false) {
            return 'Linux';
        } elseif (stripos($userAgent, 'Android') !== false) {
            return 'Android';
        } elseif (stripos($userAgent, 'iPhone') !== false || stripos($userAgent, 'iPad') !== false) {
            return 'iOS';
        }

        return 'unknown';
    }
}
