<?php

declare(strict_types=1);

namespace App\Regmel\Controller\Web\Admin;

use App\Controller\Web\Admin\AbstractAdminController;
use App\Enum\RegionEnum;
use App\Enum\UserRolesEnum;
use App\Regmel\Message\GenerateAgreementsZipMessage;
use App\Regmel\Service\Interface\ProposalAgreementServiceInterface;
use App\Service\Interface\StateServiceInterface;
use Exception;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

class ProposalAgreementAdminController extends AbstractAdminController
{
    public function __construct(
        private readonly ProposalAgreementServiceInterface $agreementService,
        private readonly StateServiceInterface $stateService,
        private readonly Security $security,
        private readonly TranslatorInterface $translator,
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    #[IsGranted(UserRolesEnum::ROLE_ADMIN->value, statusCode: self::ACCESS_DENIED_RESPONSE_CODE)]
    #[Route('/painel/admin/propostas-anuencias', name: 'admin_regmel_proposal_agreement_list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $filterRegion = $request->query->get('region');
        $filterState = $request->query->get('state');
        $filterStatus = $request->query->get('status');

        $regions = RegionEnum::cases();
        $states = $filterRegion
            ? $this->stateService->findBy(['region' => $filterRegion])
            : $this->stateService->list();
        
        $statuses = [
            ['value' => 'submitted', 'label' => 'Aguardando Validação'],
            ['value' => 'approved', 'label' => 'Aprovada'],
            ['value' => 'rejected', 'label' => 'Rejeitada'],
        ];

        $proposals = $this->agreementService->getProposalsAwaitingValidation($filterRegion, $filterState, $filterStatus);

        // Formatar dados para a view
        $formattedProposals = array_map(function ($proposal) {
            $extraFields = $proposal->getExtraFields();
            
            return [
                'id' => $proposal->getId()->toRfc4122(),
                'name' => $proposal->getName(),
                'company' => $proposal->getOrganizationFrom()?->getName() ?? 'N/A',
                'municipality' => $extraFields['city_name'] ?? 'N/A',
                'state' => $extraFields['state'] ?? 'N/A',
                'region' => $extraFields['region'] ?? 'N/A',
                'agreement_file' => $extraFields['agreement_file'] ?? null,
                'agreement_status' => $extraFields['agreement_status'] ?? null,
                'agreement_uploaded_at' => $extraFields['agreement_uploaded_at'] ?? null,
                'agreement_uploaded_by_name' => $extraFields['agreement_uploaded_by_name'] ?? 'N/A',
                'agreement_validated_at' => $extraFields['agreement_validated_at'] ?? null,
                'agreement_validated_by_name' => $extraFields['agreement_validated_by_name'] ?? null,
                'agreement_reason' => $extraFields['agreement_reason'] ?? null,
                'status_reason' => $extraFields['status_reason'] ?? null,
            ];
        }, $proposals);

        return $this->render('regmel/admin/proposal/agreements.html.twig', [
            'proposals' => $formattedProposals,
            'regions' => $regions,
            'states' => $states,
            'statuses' => $statuses,
        ], parentPath: '');
    }

    #[IsGranted(new Expression('
        is_granted("'.UserRolesEnum::ROLE_ADMIN->value.'") or
        is_granted("'.UserRolesEnum::ROLE_MUNICIPALITY->value.'")
    '), statusCode: self::ACCESS_DENIED_RESPONSE_CODE)]
    #[Route('/painel/admin/propostas/{id}/upload-agreement', name: 'admin_regmel_proposal_upload_agreement', methods: ['POST'])]
    public function uploadAgreement(Uuid $id, Request $request): Response
    {
        try {
            $file = $request->files->get('agreementFile');
            
            if (!$file) {
                $this->addFlash('error', 'Nenhum arquivo foi enviado');
                return $this->redirectBack($request);
            }

            $this->agreementService->uploadAgreementDocument($id, $file);
            $this->agreementService->sendEmailOnUpload($id);

            $this->addFlashSuccess('Documento de anuência enviado com sucesso');
        } catch (Exception $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectBack($request);
    }

    #[IsGranted(UserRolesEnum::ROLE_ADMIN->value, statusCode: self::ACCESS_DENIED_RESPONSE_CODE)]
    #[Route('/painel/admin/propostas/{id}/agreement/validate', name: 'admin_regmel_proposal_agreement_validate', methods: ['POST'])]
    public function validateAgreement(Uuid $id, Request $request): Response
    {
        try {
            $approved = $request->request->get('decision') === 'approved';
            $reason = $request->request->get('reason');

            if (empty(trim($reason))) {
                $this->addFlash('error', 'O motivo é obrigatório');
                return $this->redirectToRoute('admin_regmel_proposal_agreement_list');
            }

            $this->agreementService->validateAgreement($id, $approved, $reason);
            $this->agreementService->sendEmailOnValidation($id, $approved, $reason);

            $status = $approved ? 'aprovada' : 'rejeitada';
            $this->addFlashSuccess("Anuência {$status} com sucesso");
        } catch (Exception $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_regmel_proposal_agreement_list');
    }

    #[Route('/painel/admin/propostas/{id}/agreement-file', name: 'admin_regmel_proposal_agreement_file', methods: ['GET'])]
    public function downloadAgreementFile(Uuid $id): Response
    {
        $filePath = $this->agreementService->getAgreementDocumentPath($id);

        if (!$filePath || !file_exists($filePath)) {
            throw $this->createNotFoundException('Arquivo não encontrado');
        }

        $response = new BinaryFileResponse($filePath);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            basename($filePath)
        );

        return $response;
    }

    #[IsGranted(UserRolesEnum::ROLE_ADMIN->value, statusCode: self::ACCESS_DENIED_RESPONSE_CODE)]
    #[Route('/painel/admin/propostas/{id}/cancel-agreement', name: 'admin_regmel_proposal_cancel_agreement', methods: ['POST'])]
    public function cancelAgreement(Uuid $id, Request $request): Response
    {
        try {
            $this->agreementService->cancelAgreement($id);
            $this->addFlashSuccess('Anuência cancelada com sucesso. Proposta retornou para status "Recebida".');
        } catch (Exception $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectBack($request);
    }

    #[IsGranted(new Expression('
        is_granted("'.UserRolesEnum::ROLE_ADMIN->value.'") or
        is_granted("'.UserRolesEnum::ROLE_SUPPORT->value.'")
    '), statusCode: self::ACCESS_DENIED_RESPONSE_CODE)]
    #[Route('/painel/admin/propostas-anuencias/download', name: 'admin_regmel_proposal_agreement_download_all', methods: ['GET'])]
    public function downloadAllAgreements(): Response
    {
        /** @var \App\Entity\User $user */
        $user = $this->security->getUser();
        
        // Despacha mensagem assíncrona
        $this->messageBus->dispatch(new GenerateAgreementsZipMessage(
            userId: $user->getId()->toRfc4122(),
        ));

        $this->addFlash(
            'success',
            'Exportação iniciada! Você será notificado quando o arquivo estiver pronto.'
        );

        return $this->redirectToRoute('admin_regmel_proposal_agreement_list');
    }

    private function redirectBack(Request $request): Response
    {
        $referer = $request->headers->get('referer');
        return $referer ? $this->redirect($referer) : $this->redirectToRoute('admin_dashboard');
    }
}
