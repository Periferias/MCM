<?php

declare(strict_types=1);

namespace App\Regmel\MessageHandler;

use App\Document\NotificationDocument;
use App\DocumentService\NotificationDocumentService;
use App\Regmel\Message\GenerateMapFilesZipMessage;
use App\Regmel\Service\Interface\ProposalServiceInterface;
use App\Repository\UserRepository;
use App\Service\Interface\InitiativeServiceInterface;
use DateTime;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsMessageHandler]
final readonly class GenerateMapFilesZipMessageHandler
{
    public function __construct(
        private ProposalServiceInterface $proposalService,
        private InitiativeServiceInterface $initiativeService,
        private NotificationDocumentService $notificationService,
        private UserRepository $userRepository,
        private UrlGeneratorInterface $urlGenerator,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(GenerateMapFilesZipMessage $message): void
    {
        try {
            $this->logger->info('Iniciando geração de ZIP de mapas', [
                'userId' => $message->userId,
                'municipalityId' => $message->municipalityId,
            ]);

            // Busca as iniciativas
            $params = [];
            if ($message->municipalityId) {
                // Filtro por município se necessário
                // $params['organizationTo'] = $message->municipalityId;
            }
            
            $initiatives = $this->initiativeService->list(limit: 10000, params: $params);

            // Gera o ZIP
            $zipData = $this->proposalService->exportMapFilesAsync($initiatives, $message->userId);

            // Busca usuário
            $user = $this->userRepository->find($message->userId);
            if (!$user) {
                throw new \RuntimeException('Usuário não encontrado');
            }

            // Gera URL de download
            $downloadUrl = $this->urlGenerator->generate(
                'admin_regmel_exports_download',
                ['filename' => basename($zipData['path'])],
                UrlGeneratorInterface::ABSOLUTE_URL
            );

            // Calcula timestamp de expiração (2 horas)
            $createdAt = new DateTime();
            $expiresAt = (clone $createdAt)->modify('+2 hours');

            // Cria notificação no sistema
            $notification = new NotificationDocument();
            $notification->setSender('system');
            $notification->setTarget($message->userId);
            $notification->setContent('Exportação de Mapas Poligonais concluída');
            $notification->setContext(sprintf(
                '%d arquivos | <a href="%s" class="btn btn-sm btn-primary" data-expires-at="%s">Baixar ZIP</a> <small class="text-muted">(expira 30min após download ou 2h)</small>',
                $zipData['fileCount'],
                $downloadUrl,
                $expiresAt->format('Y-m-d H:i:s')
            ));
            $notification->setCreatedAt($createdAt);
            $notification->setVisited(false);

            // Salva notificação
            $this->notificationService->create($notification);

            $this->logger->info('ZIP de mapas gerado com sucesso', [
                'zipPath' => $zipData['path'],
                'fileCount' => $zipData['fileCount'],
            ]);

        } catch (\Throwable $e) {
            $this->logger->error('Erro ao gerar ZIP de mapas', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            // Notifica erro
            try {
                $errorNotification = new NotificationDocument();
                $errorNotification->setSender('system');
                $errorNotification->setTarget($message->userId);
                $errorNotification->setContent('❌ Erro ao gerar exportação de Mapas Poligonais');
                $errorNotification->setContext('Entre em contato com o suporte técnico.');
                $errorNotification->setCreatedAt(new DateTime());
                $errorNotification->setVisited(false);
                
                $this->notificationService->create($errorNotification);
            } catch (\Throwable $notifError) {
                $this->logger->error('Erro ao criar notificação de erro', [
                    'error' => $notifError->getMessage()
                ]);
            }
            
            throw $e;
        }
    }
}
