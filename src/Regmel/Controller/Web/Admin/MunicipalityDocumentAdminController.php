<?php

declare(strict_types=1);

namespace App\Regmel\Controller\Web\Admin;

use App\Controller\Web\Admin\AbstractAdminController;
use App\Entity\Organization;
use App\Enum\OrganizationTypeEnum;
use App\Enum\RegionEnum;
use App\Enum\UserRolesEnum;
use App\Exception\UnableCreateFileException;
use App\Regmel\Service\Interface\MunicipalityDocumentServiceInterface;
use App\Regmel\Service\Interface\MunicipalityServiceInterface;
use App\Service\Interface\OrganizationServiceInterface;
use App\Service\Interface\StateServiceInterface;
use Exception;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;
use ZipArchive;

class MunicipalityDocumentAdminController extends AbstractAdminController
{
    public function __construct(
        private readonly OrganizationServiceInterface $organizationService,
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly Security $security,
        private readonly StateServiceInterface $stateService,
        private readonly MunicipalityDocumentServiceInterface $municipalityDocumentService,
        private readonly MunicipalityServiceInterface $municipalityService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    private function renderOrganizationList(array $municipalities): Response
    {
        return $this->render('regmel/admin/municipality/documents.html.twig', [
            'municipalities' => $municipalities,
            'token' => $this->security->getUser() ? $this->jwtManager->create($this->security->getUser()) : null,
        ], parentPath: '');
    }

    #[Route('/painel/admin/municipios/{id}/oficio', name: 'regmel_municipality_document_file', methods: ['GET'])]
    public function fileForm(Uuid $id): Response
    {
        $organization = $this->organizationService->get($id);

        $this->denyAccessUnlessGranted('get_form', $organization);

        $filePath = $this->getDocumentPath($organization->getExtraFields()['form'] ?? 'null');

        if (false === file_exists($filePath)) {
            throw $this->createNotFoundException();
        }

        return new BinaryFileResponse($filePath);
    }

    #[IsGranted(new Expression('
        is_granted("'.UserRolesEnum::ROLE_ADMIN->value.'") or
        is_granted("'.UserRolesEnum::ROLE_MANAGER->value.'") or
        is_granted("'.UserRolesEnum::ROLE_SUPPORT->value.'")
    '), statusCode: self::ACCESS_DENIED_RESPONSE_CODE)]
    #[Route('/painel/admin/municipios-documentos', name: 'admin_regmel_municipality_document_list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $filterRegion = $request->query->get('region');
        $filterState = $request->query->get('state');
        $filterStatus = $request->query->get('status');

        $regions = RegionEnum::cases();
        $states = $filterRegion
            ? $this->stateService->findBy(['region' => $filterRegion])
            : $this->stateService->list();
        $status = [
            'approved',
            'rejected',
            'awaiting',
        ];

        $criteria = ['type' => OrganizationTypeEnum::MUNICIPIO->value];
        $allMunicipalities = $this->organizationService->findBy($criteria);

        $municipalities = array_filter($allMunicipalities, function (Organization $organization) use ($filterRegion, $filterState, $filterStatus) {
            $organization->addExtraField(
                'filepath',
                $this->getDocumentPath($organization->getExtraFields()['form'] ?? 'null')
            );

            $extra = $organization->getExtraFields();

            return (!$filterRegion || ($extra['region'] ?? null) === $filterRegion)
                && (!$filterState || ($extra['state'] ?? null) === $filterState)
                && (!$filterStatus || ($extra['term_status'] ?? null) === $filterStatus);
        });

        return $this->render('regmel/admin/municipality/documents.html.twig', [
            'municipalities' => $municipalities,
            'regions' => $regions,
            'states' => $states,
            'status' => $status,
            'token' => $this->security->getUser() ? $this->jwtManager->create($this->security->getUser()) : null,
        ], parentPath: '');
    }

    private function getDocumentPath(string $file): string
    {
        $path = $this->getParameter('kernel.project_dir');

        return "{$path}/storage/regmel/municipality/documents/{$file}";
    }

    private function getStatusLabel(string $status): string
    {
        return match($status) {
            'approved' => 'Aprovado',
            'rejected' => 'Rejeitado',
            'awaiting' => 'Aguardando',
            default => $status,
        };
    }

    #[IsGranted(new Expression('
        is_granted("'.UserRolesEnum::ROLE_ADMIN->value.'") or
        is_granted("'.UserRolesEnum::ROLE_MANAGER->value.'") or
        is_granted("'.UserRolesEnum::ROLE_SUPPORT->value.'")
    '), statusCode: self::ACCESS_DENIED_RESPONSE_CODE)]
    #[Route('/painel/admin/municipios/{id}/document/decision', name: 'admin_municipality_document_decision', methods: ['POST'])]
    public function handleDocumentDecision(Uuid $id, Request $request): Response
    {
        $approved = $request->request->getBoolean('approved');
        $reason = $request->request->get('reason');

        // Apenas ADMIN pode rejeitar termos
        if (!$approved && !$this->isGranted(UserRolesEnum::ROLE_ADMIN->value)) {
            $this->addFlash('error', 'Apenas administradores podem rejeitar termos de adesão');
            return $this->redirectToRoute('admin_regmel_municipality_document_list');
        }

        if (true === empty(trim($reason))) {
            $this->addFlash('error', 'O motivo é obrigatório');

            return $this->redirectToRoute('admin_regmel_municipality_document_list');
        }

        try {
            $this->municipalityDocumentService->decision($id, $approved, $reason);
        } catch (Exception $e) {
            $this->addFlash('error', 'Erro ao submeter revisão do termo');
        }

        $this->municipalityDocumentService->sendEmailDecision($id, $approved, $reason);

        return $this->redirectToRoute('admin_regmel_municipality_document_list');
    }

    #[IsGranted(new Expression('
        is_granted("'.UserRolesEnum::ROLE_ADMIN->value.'") or
        is_granted("'.UserRolesEnum::ROLE_MANAGER->value.'") or
        is_granted("'.UserRolesEnum::ROLE_SUPPORT->value.'")
    '), statusCode: self::ACCESS_DENIED_RESPONSE_CODE)]
    #[Route('/painel/admin/municipios-documentos/download', name: 'admin_regmel_municipality_document_download', methods: ['GET'])]
    public function downloadDocuments(): Response
    {
        $zipFileName = sprintf('municipality_documents_%s.zip', date('Y-m-d_H-i-s'));
        $zipFilePath = sprintf('%s/storage/regmel/municipality/documents/%s', $this->getParameter('kernel.project_dir'), $zipFileName);

        $zip = new ZipArchive();

        if (true !== $zip->open($zipFilePath, ZipArchive::CREATE)) {
            throw new UnableCreateFileException();
        }

        $municipalities = $this->organizationService->findBy([
            'type' => OrganizationTypeEnum::MUNICIPIO->value,
        ]);

        foreach ($municipalities as $municipality) {
            $filePath = $this->getDocumentPath($municipality->getExtraFields()['form'] ?? '');

            if (true === file_exists($filePath)) {
                $zip->addFile($filePath, basename($filePath));
            }
        }

        $zip->close();

        $response = new BinaryFileResponse($zipFilePath, headers: ['Content-Type' => 'application/zip']);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $zipFileName);
        $response->deleteFileAfterSend(true);

        return $response;
    }

    #[IsGranted(new Expression('
        is_granted("'.UserRolesEnum::ROLE_ADMIN->value.'") or
        is_granted("'.UserRolesEnum::ROLE_MANAGER->value.'") or
        is_granted("'.UserRolesEnum::ROLE_SUPPORT->value.'")
    '), statusCode: self::ACCESS_DENIED_RESPONSE_CODE)]
    #[Route('/painel/admin/municipios-documentos/export-users', name: 'admin_regmel_municipality_document_export_users', methods: ['GET'])]
    public function exportUsers(): Response
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Definir cabeçalhos
        $sheet->setCellValue('A1', 'Nome do Usuário');
        $sheet->setCellValue('B1', 'Email do Usuário');
        $sheet->setCellValue('C1', 'Email Institucional');
        $sheet->setCellValue('D1', 'Município');
        $sheet->setCellValue('E1', 'CNPJ');
        $sheet->setCellValue('F1', 'Quantidade de Propostas');
        $sheet->setCellValue('G1', 'Status do Termo');

        // Estilizar cabeçalho
        $headerStyle = $sheet->getStyle('A1:G1');
        $headerStyle->getFont()->setBold(true);
        $headerStyle->getFill()->setFillType('solid')->getStartColor()->setARGB('FFD3D3D3');

        $row = 2;
        $municipalities = $this->organizationService->findBy([
            'type' => OrganizationTypeEnum::MUNICIPIO->value,
        ]);

        foreach ($municipalities as $municipality) {
            // Recuperar status do termo
            $termStatus = $municipality->getExtraFields()['term_status'] ?? 'awaiting';
            
            // Pular municípios que não têm termo enviado
            if (empty($municipality->getExtraFields()['form'] ?? null)) {
                continue;
            }

            // Recuperar usuários (agents) associados ao município
            $agents = $municipality->getAgents();
            if ($agents && count($agents) > 0) {
                foreach ($agents as $agent) {
                    $user = $agent->getUser();
                    if ($user) {
                        // Contar propostas do município
                        $proposalsCount = count($this->municipalityService->getProposals($municipality));
                        
                        $sheet->setCellValue('A' . $row, $agent->getName() ?? '');
                        $sheet->setCellValue('B' . $row, $user->getEmail());
                        $sheet->setCellValue('C' . $row, $municipality->getExtraFields()['email'] ?? '');
                        $sheet->setCellValue('D' . $row, $municipality->getName());
                        $sheet->setCellValue('E' . $row, $municipality->getExtraFields()['cnpj'] ?? '');
                        $sheet->setCellValue('F' . $row, $proposalsCount);
                        $sheet->setCellValue('G' . $row, $this->getStatusLabel($termStatus));
                        $row++;
                    }
                }
            }
        }

        // Adicionar linha de informação se não houver dados
        if ($row === 2) {
            $sheet->setCellValue('A2', 'Nenhum usuário encontrado');
        }

        // Ajustar largura das colunas
        $sheet->getColumnDimension('A')->setAutoSize(true);
        $sheet->getColumnDimension('B')->setAutoSize(true);
        $sheet->getColumnDimension('C')->setAutoSize(true);
        $sheet->getColumnDimension('D')->setAutoSize(true);
        $sheet->getColumnDimension('E')->setAutoSize(true);
        $sheet->getColumnDimension('F')->setAutoSize(true);
        $sheet->getColumnDimension('G')->setAutoSize(true);

        // Criar arquivo temporário
        $fileName = sprintf('usuarios_termos_adesao_%s.xlsx', date('Y-m-d_H-i-s'));
        $filePath = sprintf('%s/storage/regmel/municipality/exports/%s', $this->getParameter('kernel.project_dir'), $fileName);

        // Garantir que a pasta existe
        $exportDir = dirname($filePath);
        if (!is_dir($exportDir)) {
            mkdir($exportDir, 0755, true);
        }

        // Salvar arquivo
        $writer = new Xlsx($spreadsheet);
        $writer->save($filePath);

        $response = new BinaryFileResponse($filePath, headers: ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $fileName);
        $response->deleteFileAfterSend(true);

        return $response;
    }
}
