<?php

declare(strict_types=1);

namespace App\Regmel\Service\Interface;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

interface ProposalAgreementServiceInterface
{
    /**
     * Upload agreement document for a proposal by municipality.
     */
    public function uploadAgreementDocument(Uuid $proposalId, UploadedFile $file): void;

    /**
     * Validate agreement document (approve or reject) by admin.
     */
    public function validateAgreement(Uuid $proposalId, bool $approved, string $reason): void;

    /**
     * Get proposals awaiting agreement validation.
     */
    public function getProposalsAwaitingValidation(?string $region = null, ?string $state = null, ?string $status = null): array;

    /**
     * Get agreement document path for a proposal.
     */
    public function getAgreementDocumentPath(Uuid $proposalId): ?string;

    /**
     * Generate ZIP with all agreement documents.
     */
    public function exportAllAgreements(): string;

    /**
     * Generate ZIP with all agreement documents asynchronously.
     */
    public function exportAllAgreementsAsync(string $userId): array;

    /**
     * Send email notification when municipality uploads agreement.
     */
    public function sendEmailOnUpload(Uuid $proposalId): void;

    /**
     * Send email notification when admin validates agreement.
     */
    public function sendEmailOnValidation(Uuid $proposalId, bool $approved, string $reason): void;

    /**
     * Count total agreement documents.
     */
    public function countAgreements(): int;

    /**
     * Count agreement documents awaiting approval.
     */
    public function countAgreementsAwaitingApproval(): int;

    /**
     * Cancel agreement and return proposal to RECEBIDA status.
     */
    public function cancelAgreement(Uuid $proposalId): void;
}
