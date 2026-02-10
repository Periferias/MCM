<?php

declare(strict_types=1);

namespace App\Regmel\Service;

use App\Enum\StatusProposalEnum;
use App\Regmel\Service\Interface\ProposalAgreementServiceInterface;
use App\Service\Interface\EmailServiceInterface;
use App\Service\Interface\FileServiceInterface;
use App\Service\Interface\InitiativeServiceInterface;
use DateTime;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;
use ZipArchive;

readonly class ProposalAgreementService implements ProposalAgreementServiceInterface
{
    public function __construct(
        private InitiativeServiceInterface $initiativeService,
        private FileServiceInterface $fileService,
        private EmailServiceInterface $emailService,
        private Security $security,
        private ParameterBagInterface $parameterBag,
    ) {
    }

    public function uploadAgreementDocument(Uuid $proposalId, UploadedFile $file): void
    {
        $proposal = $this->initiativeService->get($proposalId);
        $extraFields = $proposal->getExtraFields();

        // Verificar se município tem termo aprovado
        $municipality = $proposal->getOrganizationTo();
        if (!$municipality || ($municipality->getExtraFields()['term_status'] ?? null) !== 'approved') {
            throw new \InvalidArgumentException('Município deve ter termo de adesão aprovado para enviar anuência');
        }

        // Verificar se proposta está com status "Recebida" ou se o documento foi rejeitado
        $currentStatus = $extraFields['status'] ?? null;
        $agreementStatus = $extraFields['agreement_status'] ?? null;
        
        $canUpload = $currentStatus === StatusProposalEnum::RECEBIDA->value 
            || ($currentStatus === StatusProposalEnum::AGUARDANDO_AVALIACAO_ANUENCIA->value && $agreementStatus === 'rejected');
        
        if (!$canUpload) {
            throw new \InvalidArgumentException('Proposta deve estar com status "Recebida" ou com documento de anuência rejeitado para enviar/reenviar anuência');
        }

        // Incrementar versão se for reenvio
        $version = 1;
        if (isset($extraFields['agreement_file'])) {
            // Extrair versão atual e incrementar
            if (preg_match('/_v(\d+)\.pdf$/', $extraFields['agreement_file'], $matches)) {
                $version = (int)$matches[1] + 1;
            }
        }

        // Upload do arquivo
        $fileName = sprintf(
            '%s_agreement_v%02d',
            $proposalId->toRfc4122(),
            $version
        );

        $uploadedFile = $this->fileService->uploadMixedFile(
            $file,
            '/regmel/proposals/agreements',
            $fileName
        );

        // Atualizar extra_fields
        $user = $this->security->getUser();
        $extraFields['agreement_file'] = $uploadedFile->getFilename();
        $extraFields['agreement_status'] = 'submitted';
        $extraFields['agreement_uploaded_at'] = (new DateTime())->format('Y-m-d H:i:s');
        $extraFields['agreement_uploaded_by'] = $user?->getId()->toRfc4122();
        $extraFields['agreement_uploaded_by_name'] = $user?->getName();
        
        // Mudar status da proposta para "Aguardando Avaliação da Anuência"
        $extraFields['status'] = StatusProposalEnum::AGUARDANDO_AVALIACAO_ANUENCIA->value;
        $extraFields['status_updated_by'] = $user?->getId()->toRfc4122();
        $extraFields['status_updated_at'] = (new DateTime())->format('Y-m-d H:i:s');
        $extraFields['status_updated_by_name'] = $user?->getName();

        // Limpar motivo anterior se houver
        unset($extraFields['agreement_reason']);

        $proposal->setExtraFields($extraFields);
        $this->initiativeService->update(
            $proposalId,
            ['extra_fields' => $extraFields]
        );
    }

    public function validateAgreement(Uuid $proposalId, bool $approved, string $reason): void
    {
        $proposal = $this->initiativeService->get($proposalId);
        $extraFields = $proposal->getExtraFields();

        if (($extraFields['agreement_status'] ?? null) !== 'submitted') {
            throw new \InvalidArgumentException('Apenas anuências com documentação reenviada podem ser reavaliadas');
        }

        $user = $this->security->getUser();
        
        if ($approved) {
            $extraFields['agreement_status'] = 'approved';
            // Atualizar status da proposta para "Anuída"
            $extraFields['status'] = StatusProposalEnum::ANUIDA->value;
        } else {
            $extraFields['agreement_status'] = 'rejected';
        }

        $extraFields['agreement_validated_at'] = (new DateTime())->format('Y-m-d H:i:s');
        $extraFields['agreement_validated_by'] = $user?->getId()->toRfc4122();
        $extraFields['agreement_validated_by_name'] = $user?->getName();
        $extraFields['agreement_reason'] = $reason;

        $proposal->setExtraFields($extraFields);
        $this->initiativeService->update(
            $proposalId,
            ['extra_fields' => $extraFields]
        );
    }

    public function getProposalsAwaitingValidation(?string $region = null, ?string $state = null, ?string $status = null): array
    {
        $allProposals = $this->initiativeService->list(limit: 10000);

        return array_filter($allProposals, function ($proposal) use ($region, $state, $status) {
            $extraFields = $proposal->getExtraFields();
            
            // Filtrar apenas propostas com documento de anuência enviado ou validado
            $agreementStatus = $extraFields['agreement_status'] ?? null;
            if (!in_array($agreementStatus, ['submitted', 'approved', 'rejected'])) {
                return false;
            }

            // Aplicar filtros
            if ($region && ($extraFields['region'] ?? null) !== $region) {
                return false;
            }

            if ($state && ($extraFields['state'] ?? null) !== $state) {
                return false;
            }

            if ($status && $agreementStatus !== $status) {
                return false;
            }

            return true;
        });
    }

    public function getAgreementDocumentPath(Uuid $proposalId): ?string
    {
        $proposal = $this->initiativeService->get($proposalId);
        $extraFields = $proposal->getExtraFields();

        $fileName = $extraFields['agreement_file'] ?? null;
        if (!$fileName) {
            return null;
        }

        $path = $this->parameterBag->get('kernel.project_dir');
        return "{$path}/storage/regmel/proposals/agreements/{$fileName}";
    }

    public function exportAllAgreements(): string
    {
        $proposals = $this->getProposalsAwaitingValidation();
        
        $zip = new ZipArchive();
        $zipFileName = sprintf('anuencias_%s.zip', date('Y-m-d_H-i-s'));
        $zipPath = sys_get_temp_dir() . '/' . $zipFileName;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Não foi possível criar arquivo ZIP');
        }

        foreach ($proposals as $proposal) {
            $filePath = $this->getAgreementDocumentPath($proposal->getId());
            if ($filePath && file_exists($filePath)) {
                $extraFields = $proposal->getExtraFields();
                $municipalityName = $extraFields['city_name'] ?? 'Municipio';
                $companyName = $proposal->getOrganizationFrom()?->getName() ?? 'Empresa';
                
                $zipEntryName = sprintf(
                    '%s_%s_%s',
                    $municipalityName,
                    $companyName,
                    basename($filePath)
                );
                
                $zip->addFile($filePath, $zipEntryName);
            }
        }

        $zip->close();
        return $zipPath;
    }

    public function sendEmailOnUpload(Uuid $proposalId): void
    {
        $proposal = $this->initiativeService->get($proposalId);
        $extraFields = $proposal->getExtraFields();
        
        $municipalityName = $extraFields['city_name'] ?? 'Município';
        $proposalName = $proposal->getName();
        $companyName = $proposal->getOrganizationFrom()?->getName() ?? 'Empresa';

        // Email para admins
        $adminEmails = ['admin@regmel.com']; // TODO: Buscar emails dos admins do banco

        $subject = "Nova Anuência Enviada - {$municipalityName}";
        $message = "O município {$municipalityName} enviou o documento de anuência para a proposta '{$proposalName}' da empresa {$companyName}.\n\n";
        $message .= "Acesse o painel administrativo para validar o documento.";

        $this->emailService->send($adminEmails, $subject, $message);
    }

    public function sendEmailOnValidation(Uuid $proposalId, bool $approved, string $reason): void
    {
        $proposal = $this->initiativeService->get($proposalId);
        $extraFields = $proposal->getExtraFields();
        
        $municipalityName = $extraFields['city_name'] ?? 'Município';
        $proposalName = $proposal->getName();
        $companyName = $proposal->getOrganizationFrom()?->getName() ?? 'Empresa';
        
        // Email para município
        $municipalityEmails = [];
        $municipality = $proposal->getOrganizationTo();
        if ($municipality && $municipality->getAgents()) {
            foreach ($municipality->getAgents() as $agent) {
                if ($agent->getUser()) {
                    $municipalityEmails[] = $agent->getUser()->getEmail();
                }
            }
        }

        // Email para empresa
        $companyEmails = [];
        $company = $proposal->getOrganizationFrom();
        if ($company && $company->getAgents()) {
            foreach ($company->getAgents() as $agent) {
                if ($agent->getUser()) {
                    $companyEmails[] = $agent->getUser()->getEmail();
                }
            }
        }

        // Se rejeitado, enviar apenas para município. Se aprovado, enviar para ambos
        $allEmails = $approved 
            ? array_merge($municipalityEmails, $companyEmails)
            : $municipalityEmails;
            
        if (empty($allEmails)) {
            // Log de erro para debug
            error_log(sprintf(
                '[ProposalAgreementService] Nenhum email encontrado para enviar notificação de %s da proposta %s. Município ID: %s, Empresa ID: %s',
                $approved ? 'aprovação' : 'rejeição',
                $proposalId->toRfc4122(),
                $municipality?->getId()->toRfc4122() ?? 'N/A',
                $company?->getId()->toRfc4122() ?? 'N/A'
            ));
            throw new \RuntimeException(
                'Não foi possível enviar o email: nenhum destinatário válido encontrado. ' .
                'Verifique se o município possui agentes com emails cadastrados.'
            );
        }

        $template = $approved 
            ? '_emails/notifications/proposal/agreement-approved.html.twig'
            : '_emails/notifications/proposal/agreement-rejected.html.twig';

        $subject = $approved 
            ? "Documento de Anuência Aprovado - {$proposalName}"
            : "Documento de Anuência Rejeitado - {$proposalName}";

        $loginPage = $this->parameterBag->get('app.url') ?? 'https://localhost';

        $this->emailService->sendTemplatedEmail(
            $allEmails,
            $subject,
            $template,
            [
                'municipalityName' => $municipalityName,
                'proposalName' => $proposalName,
                'companyName' => $companyName,
                'reason' => $reason,
                'loginPage' => $loginPage,
            ]
        );
    }
}
