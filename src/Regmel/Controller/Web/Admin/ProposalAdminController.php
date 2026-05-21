<?php

declare(strict_types=1);

namespace App\Regmel\Controller\Web\Admin;

use App\Controller\Web\Admin\AbstractAdminController;
use App\Entity\Initiative;
use App\Enum\RegionEnum;
use App\Enum\StatusProposalEnum;
use App\Enum\UserRolesEnum;
use App\Environment\ConfigEnvironment;
use App\Regmel\Service\Interface\ProposalServiceInterface;
use App\Regmel\Service\ProposalOrderingService;
use App\Service\Interface\CityServiceInterface;
use App\Service\Interface\InitiativeServiceInterface;
use App\Service\Interface\InscriptionOpportunityServiceInterface;
use App\Service\Interface\OrganizationServiceInterface;
use App\Service\Interface\PhaseServiceInterface;
use App\Service\Interface\StateServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

class ProposalAdminController extends AbstractAdminController
{
    public function __construct(
        private readonly OrganizationServiceInterface $organizationService,
        private readonly ProposalServiceInterface $proposalService,
        private readonly StateServiceInterface $stateService,
        private readonly CityServiceInterface $cityService,
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly Security $security,
        private readonly InitiativeServiceInterface $initiativeService,
        private readonly ConfigEnvironment $configEnvironment,
        private readonly PhaseServiceInterface $phaseService,
        private readonly TranslatorInterface $translator,
        private readonly EntityManagerInterface $entityManager,
        private readonly ProposalOrderingService $proposalOrderingService,
        public readonly InscriptionOpportunityServiceInterface $inscriptionOpportunityService,
    ) {
    }

    #[IsGranted(new Expression('
        is_granted("'.UserRolesEnum::ROLE_ADMIN->value.'") or 
        is_granted("'.UserRolesEnum::ROLE_MANAGER->value.'") or
        is_granted("'.UserRolesEnum::ROLE_SUPPORT->value.'") or
        is_granted("'.UserRolesEnum::ROLE_CAIXA->value.'")
    '), statusCode: self::ACCESS_DENIED_RESPONSE_CODE)]
    #[Route('/painel/admin/propostas', name: 'admin_regmel_proposal_list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $statuses = StatusProposalEnum::cases();
        $status = $request->query->get('status');
        $statusGroup = $request->query->get('status_group');
        $regions = RegionEnum::cases();
        $region = $request->query->get('region');
        $state = $request->query->get('state');
        $city = $request->query->get('city');
        $anticipation = $request->query->get('anticipation');
        $cities = [];
        $states = $this->stateService->list();
        $anticipationOptions = [
            ['value' => 'true', 'label' => $this->translator->trans('proposal.in_anticipation')],
            ['value' => 'false', 'label' => $this->translator->trans('proposal.no_anticipation')],
        ];

        if ($region) {
            $states = $this->stateService->findBy(['region' => $region]);
        }

        if ($state) {
            $cities = $this->cityService->findByState($state);
        }

        $filtered = $this->initiativeService->listFiltered($region, $state, $city, $status, $anticipation);

        // Filtrar propostas por role (ROLE_CAIXA só vê SELECIONADA e CLASSIFICADA)
        $isCaixa = $this->security->isGranted(UserRolesEnum::ROLE_CAIXA->value);
        if ($isCaixa) {
            $filtered = array_filter($filtered, function (Initiative $proposal) {
                $proposalStatus = $proposal->getExtraFields()['status'] ?? '';

                return StatusProposalEnum::isRanked($proposalStatus);
            });
        }

        $env = $this->configEnvironment->aurora();

        $proposals = array_map(function (Initiative $initiative) use ($env) {
            $organization = $initiative->getOrganizationFrom();
            $extraFields = $initiative->getExtraFields();
            $areaCharacteristic = $extraFields['area_characteristic'] ?? null;
            $quantityHouses = (int) ($extraFields['quantity_houses'] ?? 0);
            $orgExtraFields = $organization?->getExtraFields() ?? [];

            // Generate download URLs for files
            $mapFileUrl = isset($extraFields['map_file'])
                ? $this->generateUrl('admin_regmel_proposal_mapa', ['id' => $initiative->getId()])
                : '';
            $projectFileUrl = isset($extraFields['project_file'])
                ? $this->generateUrl('admin_regmel_proposal_projeto', ['id' => $initiative->getId()])
                : '';

            // Buscar dados do representante (usuário owner da organização)
            $representativeName = '';
            $representativeCpf = '';
            $representativeEmail = '';
            $representativePhone = '';

            if ($organization) {
                try {
                    $agent = $organization->getOwner();
                    if ($agent) {
                        $owner = $agent->getUser();
                        if ($owner) {
                            $representativeName = trim(($owner->getFirstname() ?? '').' '.($owner->getLastname() ?? ''));
                            $representativeEmail = $owner->getEmail() ?? '';

                            // Telefone e CPF estão em extra_fields do agent
                            $agentExtraFields = $agent->getExtraFields() ?? [];
                            $representativeCpf = $agentExtraFields['cpf'] ?? '';
                            $representativePhone = $agentExtraFields['telefone'] ?? '';
                        }
                    }
                } catch (\Exception $e) {
                    // Se erro ao buscar dados do representante, deixa vazio
                }
            }

            return [
                'id' => $initiative->getId()->toRfc4122(),
                'name' => $initiative->getName(),
                'company' => $organization?->getName() ?? '',
                'institution_type' => $orgExtraFields['tipo'] ?? 'Não Informado',
                'city_name' => $extraFields['city_name'] ?? '',
                'city_code' => $extraFields['city_code'] ?? $extraFields['cityCode'] ?? '',
                'region' => $extraFields['region'] ?? '',
                'state' => $extraFields['state'] ?? '',
                'status' => $extraFields['status'] ?? '',
                'quantity_houses' => $quantityHouses,
                'area_size' => $extraFields['area_size'] ?? '',
                'created_at' => $initiative->getCreatedAt()->format('d/m/Y H:i:s'),
                'created_by' => $initiative->getCreatedBy()?->getName() ?? '',
                'area_option' => null !== $areaCharacteristic ? ($env['proposals']['area_characteristics'][$areaCharacteristic] ?? '') : '',
                'price_per_house' => (float) ($env['variables']['price_per_household'] ?? 1),
                'map_file' => $mapFileUrl,
                'map_file_type' => isset($extraFields['map_file']) ? strtolower(pathinfo($extraFields['map_file'], PATHINFO_EXTENSION)) : '',
                'project_file' => $projectFileUrl,
                'anticipation' => $extraFields['anticipation'] ?? '',
                'snpr_affiliation' => $extraFields['snpr_affiliation'] ?? 'Não',
                'snpr_affiliation_details' => $extraFields['snpr_affiliation_details'] ?? '',
                'zipcode' => $extraFields['zipcode'] ?? 'Não Informado',
                'address' => $extraFields['address'] ?? 'Não Informado',
                'status_updated_by_name' => $extraFields['status_updated_by_name'] ?? 'Não informado',
                'agreement_uploaded_by_name' => $extraFields['agreement_uploaded_by_name'] ?? 'Não informado',
                'agreement_status' => $extraFields['agreement_status'] ?? null,
                'status_reason' => $extraFields['status_reason'] ?? null,
                'evaluation_ranking' => $extraFields['evaluation_ranking'] ?? null,
                'agreement_reason' => $extraFields['agreement_reason'] ?? null,
                // Dados da empresa/OSC
                'company_cnpj' => $orgExtraFields['cnpj'] ?? '',
                'company_email' => $orgExtraFields['email'] ?? '',
                'company_phone' => $orgExtraFields['telefone'] ?? $orgExtraFields['phone'] ?? '',
                // Dados do representante (usuário owner da empresa)
                'representative_name' => $representativeName,
                'representative_cpf' => $representativeCpf,
                'representative_email' => $representativeEmail,
                'representative_phone' => $representativePhone,
            ];
        }, $filtered);

        return $this->render('regmel/admin/proposal/list.html.twig', [
            'proposals' => $proposals,
            'regions' => $regions,
            'statuses' => $statuses,
            'states' => $states,
            'cities' => $cities,
            'anticipation' => $anticipation ?? '',
            'anticipationOption' => $anticipationOptions,
        ], parentPath: '');
    }

    #[IsGranted(new Expression('
        is_granted("'.UserRolesEnum::ROLE_COMPANY->value.'")
    '), statusCode: self::ACCESS_DENIED_RESPONSE_CODE)]
    #[Route('/painel/admin/empresas/{id}/nova-proposta', name: 'admin_regmel_proposal_add', methods: ['GET', 'POST'])]
    public function add(Uuid $id, Request $request): Response
    {
        $opportunity = $this->inscriptionOpportunityService->findOpportunityByOrganization($id);

        $isPhaseActive = $this->phaseService->isPhaseActive($opportunity->getId());

        $maxFileSize = $this->getParameter('max_file_size');

        if (false === $isPhaseActive) {
            return $this->render(
                'regmel/admin/proposal/add-proposal-not-active.html.twig',
                parentPath: ''
            );
        }

        $user = $this->security->getUser();
        $company = $this->organizationService->get($id);

        if (false === $request->isMethod(Request::METHOD_POST)) {
            $states = $this->stateService->list();
            $cities = $this->cityService->findBy();
            // $opportunities = $this->registerService->findOpportunitiesBy(OrganizationTypeEnum::EMPRESA);

            return $this->render('regmel/admin/proposal/add.html.twig', [
                'states' => $states,
                'cities' => $cities,
                'token' => $this->jwtManager->create($user),
                'company' => $company,
                'maxFileSize' => $maxFileSize,
                // 'opportunities' => $opportunities,
            ], parentPath: '');
        }

        $this->proposalService->saveProposal(
            $company,
            $request->request->all(),
            $request->files->get('map'),
            $request->files->get('project')
        );

        $this->addFlashSuccess('Pronto, nova proposta enviada');

        return $this->redirectToRoute('admin_regmel_company_list', [
            'id' => $id,
        ]);
    }

    #[Route('/painel/admin/propostas/{id}/mapa', name: 'admin_regmel_proposal_mapa', methods: ['GET'])]
    public function getMapaPoligonal(Uuid $id): Response
    {
        $initiative = $this->initiativeService->get($id);

        $filePath = $this->getDocumentPath($initiative->getExtraFields()['map_file'] ?? 'null');

        if (false === file_exists($filePath)) {
            throw $this->createNotFoundException();
        }

        return new BinaryFileResponse($filePath);
    }

    #[Route('/painel/admin/propostas/{id}/projeto', name: 'admin_regmel_proposal_projeto', methods: ['GET'])]
    public function getProjeto(Uuid $id): Response
    {
        $initiative = $this->initiativeService->get($id);

        $fileName = $initiative->getExtraFields()['project_file'] ?? 'null';

        $filePath = $this->getDocumentPath($fileName);

        if (false === file_exists($filePath)) {
            throw $this->createNotFoundException();
        }

        $response = new BinaryFileResponse($filePath);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $fileName);

        return $response;
    }

    private function getDocumentPath(string $file): string
    {
        $path = $this->getParameter('kernel.project_dir');

        return "{$path}/storage/regmel/company/documents/{$file}";
    }

    #[IsGranted(new Expression('
        is_granted("'.UserRolesEnum::ROLE_ADMIN->value.'") or
        is_granted("'.UserRolesEnum::ROLE_MANAGER->value.'") or
        is_granted("'.UserRolesEnum::ROLE_SUPPORT->value.'") or
        is_granted("'.UserRolesEnum::ROLE_MUNICIPALITY->value.'") or
        is_granted("'.UserRolesEnum::ROLE_CAIXA->value.'")
    '), statusCode: self::ACCESS_DENIED_RESPONSE_CODE)]
    #[Route('/painel/admin/propostas/list/download', name: 'admin_regmel_proposal_list_download', methods: ['GET'])]
    public function exportProposalsCsv(Request $request): Response
    {
        $user = $this->security->getUser();
        $isMunicipality = $this->security->isGranted(UserRolesEnum::ROLE_MUNICIPALITY->value);
        $isCaixa = $this->security->isGranted(UserRolesEnum::ROLE_CAIXA->value);

        $status = $request->query->get('status');
        $statusGroup = $request->query->get('status_group');

        if ($isMunicipality) {
            $agent = $user->getAgents()->filter(fn ($agent) => $agent->isMain())->first();
            $municipality = $agent->getOrganizations()->first();

            if ($status) {
                // Usar listFiltered para filtrar por status
                $initiatives = $this->initiativeService->listFiltered(
                    region: null,
                    state: null,
                    cityId: null,
                    status: $status,
                    anticipation: null
                );
                // Filtrar apenas propostas da municipalidade
                $initiatives = array_filter($initiatives, fn ($init) => $init->getOrganizationTo()?->getId() === $municipality->getId());
            } else {
                $initiatives = $this->initiativeService->list(limit: 10000, params: ['organizationTo' => $municipality]);
            }
        } elseif ($isCaixa) {
            // ROLE_CAIXA pode baixar apenas propostas SELECIONADA e CLASSIFICADA
            if ($status) {
                $initiatives = $this->initiativeService->listFiltered(
                    region: null,
                    state: null,
                    cityId: null,
                    status: $status,
                    anticipation: null
                );
            } else {
                $initiatives = $this->initiativeService->listAllIncludingDeleted(limit: 10000);
            }

            $initiatives = array_filter($initiatives, function (Initiative $proposal) {
                $proposalStatus = $proposal->getExtraFields()['status'] ?? '';

                return StatusProposalEnum::isRanked($proposalStatus);
            });
        } else {
            if ($status) {
                // Usar listFiltered para filtrar por status
                $initiatives = $this->initiativeService->listFiltered(
                    region: null,
                    state: null,
                    cityId: null,
                    status: $status,
                    anticipation: null
                );
            } else {
                // Incluir propostas deletadas na exportação CSV
                $initiatives = $this->initiativeService->listAllIncludingDeleted(limit: 10000);
            }
        }

        if ('selecionadas' === $statusGroup) {
            $initiatives = array_filter($initiatives, function (Initiative $proposal) {
                $proposalStatus = $proposal->getExtraFields()['status'] ?? '';

                return StatusProposalEnum::isSelected($proposalStatus);
            });
        }

        if ('classificadas' === $statusGroup) {
            $initiatives = array_filter($initiatives, function (Initiative $proposal) {
                $proposalStatus = $proposal->getExtraFields()['status'] ?? '';

                return StatusProposalEnum::isClassified($proposalStatus);
            });
        }

        return $this->proposalService->generateSpreadSheet($initiatives, 'propostas', null);
    }

    #[IsGranted(new Expression('
        is_granted("'.UserRolesEnum::ROLE_ADMIN->value.'") or
        is_granted("'.UserRolesEnum::ROLE_MANAGER->value.'") or
        is_granted("'.UserRolesEnum::ROLE_SUPPORT->value.'") or
        is_granted("'.UserRolesEnum::ROLE_MUNICIPALITY->value.'") or
        is_granted("'.UserRolesEnum::ROLE_CAIXA->value.'")
    '), statusCode: self::ACCESS_DENIED_RESPONSE_CODE)]
    #[Route('/painel/admin/propostas/list/download-project-files', name: 'admin_regmel_proposal_project_file_download', methods: ['GET'])]
    public function exportProjectFiles(): Response
    {
        $user = $this->security->getUser();
        $isMunicipality = $this->security->isGranted(UserRolesEnum::ROLE_MUNICIPALITY->value);
        $isCaixa = $this->security->isGranted(UserRolesEnum::ROLE_CAIXA->value);

        if ($isMunicipality) {
            $agent = $user->getAgents()->filter(fn ($agent) => $agent->isMain())->first();
            $municipality = $agent->getOrganizations()->first();
            $initiatives = $this->initiativeService->list(limit: 10000, params: ['organizationTo' => $municipality]);
        } elseif ($isCaixa) {
            // ROLE_CAIXA pode baixar apenas propostas SELECIONADA e CLASSIFICADA
            $initiatives = $this->initiativeService->list(limit: 10000);

            $initiatives = array_filter($initiatives, function (Initiative $proposal) {
                $proposalStatus = $proposal->getExtraFields()['status'] ?? '';

                return StatusProposalEnum::isRanked($proposalStatus);
            });
        } else {
            $initiatives = $this->initiativeService->list(limit: 10000);
        }

        $zipFileName = sprintf('arquivos_geográficos_%s.zip', date('Y-m-d_H-i-s'));

        $filePath = $this->proposalService->exportProjectFiles($initiatives);

        $response = new BinaryFileResponse($filePath, headers: ['Content-Type' => 'application/zip']);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $zipFileName);
        $response->deleteFileAfterSend(true);

        return $response;
    }

    #[IsGranted(new Expression('
        is_granted("'.UserRolesEnum::ROLE_ADMIN->value.'") or
        is_granted("'.UserRolesEnum::ROLE_MANAGER->value.'") or
        is_granted("'.UserRolesEnum::ROLE_SUPPORT->value.'") or
        is_granted("'.UserRolesEnum::ROLE_MUNICIPALITY->value.'")
    '), statusCode: self::ACCESS_DENIED_RESPONSE_CODE)]
    #[Route('/painel/admin/propostas/list/download-map-files', name: 'admin_regmel_proposal_map_file_download', methods: ['GET'])]
    public function exportMapFiles(): Response
    {
        $user = $this->security->getUser();
        $isMunicipality = $this->security->isGranted(UserRolesEnum::ROLE_MUNICIPALITY->value);

        if ($isMunicipality) {
            $agent = $user->getAgents()->filter(fn ($agent) => $agent->isMain())->first();
            $municipality = $agent->getOrganizations()->first();
            $initiatives = $this->initiativeService->list(limit: 10000, params: ['organizationTo' => $municipality]);
        } else {
            $initiatives = $this->initiativeService->list(limit: 10000);
        }

        $zipFileName = sprintf('mapas_poligonais_%s.zip', date('Y-m-d_H-i-s'));

        $filePath = $this->proposalService->exportMapFiles($initiatives);

        $response = new BinaryFileResponse($filePath, headers: ['Content-Type' => 'application/zip']);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $zipFileName);
        $response->deleteFileAfterSend(true);

        return $response;
    }

    #[IsGranted(new Expression('
        is_granted("'.UserRolesEnum::ROLE_ADMIN->value.'") or
        is_granted("'.UserRolesEnum::ROLE_MANAGER->value.'")
    '), statusCode: self::ACCESS_DENIED_RESPONSE_CODE)]
    #[Route('/painel/admin/propostas/{id}/anticipation-files/download', name: 'admin_regmel_proposal_anticipation_files_download', methods: ['GET'])]
    public function downloadAnticipationFiles(Uuid $id): Response
    {
        $initiative = $this->initiativeService->get($id);

        $extraFields = $initiative->getExtraFields();

        $municipality = $extraFields['city_name'];

        $proposal = $initiative->getName();

        $company = $initiative->getOrganizationFrom()?->getName();

        if (($extraFields['anticipation'] ?? 'false') !== 'true') {
            throw $this->createNotFoundException('Proposta não possui antecipação.');
        }

        $zipFilePath = $this->proposalService->exportAnticipationFiles([$initiative]);

        $zipFileName = sprintf('%s_documentos_antecipacao_%s_%s.zip', $company, $proposal, $municipality);

        $response = new BinaryFileResponse($zipFilePath, headers: ['Content-Type' => 'application/zip']);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $zipFileName);
        $response->deleteFileAfterSend(true);

        return $response;
    }

    #[IsGranted(new Expression('
        is_granted("'.UserRolesEnum::ROLE_ADMIN->value.'") or 
        is_granted("'.UserRolesEnum::ROLE_MANAGER->value.'")
    '), statusCode: self::ACCESS_DENIED_RESPONSE_CODE)]
    #[Route('/painel/admin/propostas/{id}/status', name: 'admin_regmel_proposal_update_status', methods: ['POST'])]
    public function updateStatusProposal(Request $request, Uuid $id): Response
    {
        $redirectUrl = $request->headers->get('referer');
        $redirectResponse = fn (): Response => $redirectUrl
            ? $this->redirect($redirectUrl)
            : $this->redirectToRoute('admin_regmel_proposal_list');

        $status = StatusProposalEnum::from($request->request->get('status'));
        $reason = $request->request->get('reason');

        // Verifica se o município tem termo aprovado antes de permitir anuência ou seleção
        $proposal = $this->initiativeService->get($id);
        $municipality = $proposal->getOrganizationTo();

        if ($municipality) {
            $termStatus = $municipality->getExtraFields()['term_status'] ?? null;

            if (
                'approved' !== $termStatus
                && in_array($status, [
                    StatusProposalEnum::ANUIDA,
                    StatusProposalEnum::NAO_ANUIDA,
                    StatusProposalEnum::SELECIONADA,
                    StatusProposalEnum::SELECIONADA_DESEMPATE,
                ])
            ) {
                $this->addFlash('error', 'Não é possível anuir ou selecionar proposta sem termo de adesão aprovado');

                return $redirectResponse();
            }
        }

        if (true === empty(trim($reason))) {
            $this->addFlash('error', 'O motivo é obrigatório');
        } else {
            $this->proposalService->updateStatusProposal($id, $status, $reason);
        }

        return $redirectResponse();
    }

    #[IsGranted(new Expression('
        is_granted("'.UserRolesEnum::ROLE_ADMIN->value.'") or 
        is_granted("'.UserRolesEnum::ROLE_MANAGER->value.'")
    '), statusCode: self::ACCESS_DENIED_RESPONSE_CODE)]
    #[Route('/painel/admin/propostas/bulk-update-status', name: 'admin_regmel_proposal_bulk_update_status', methods: ['POST'])]
    public function bulkUpdateStatus(Request $request): Response
    {
        $body = json_decode($request->getContent(), true);
        $selectedRows = $body['ids'] ?? [];
        $status = $body['status'] ?? '';

        $this->proposalService->bulkUpdateStatus($selectedRows, $status);

        return $this->redirectToRoute('admin_regmel_proposal_list');
    }

    #[IsGranted(UserRolesEnum::ROLE_ADMIN->value, statusCode: self::ACCESS_DENIED_RESPONSE_CODE)]
    #[Route('/painel/admin/propostas/reorder', name: 'admin_regmel_proposal_reorder', methods: ['POST'])]
    public function reorder(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);

            if (!isset($data['reordering']) || !is_array($data['reordering'])) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Campo "reordering" deve ser um array de objetos com proposalId e newOrder.',
                ], JsonResponse::HTTP_BAD_REQUEST);
            }

            $this->proposalOrderingService->reorderProposals($data['reordering']);

            return new JsonResponse([
                'success' => true,
                'message' => 'Propostas reordenadas com sucesso.',
            ]);
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse([
                'success' => false,
                'message' => $exception->getMessage(),
            ], JsonResponse::HTTP_BAD_REQUEST);
        } catch (\Exception $exception) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Erro ao reordenar propostas: '.$exception->getMessage(),
            ], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[IsGranted(UserRolesEnum::ROLE_ADMIN->value, statusCode: self::ACCESS_DENIED_RESPONSE_CODE)]
    #[Route('/painel/admin/propostas/{id}/ranking', name: 'admin_regmel_proposal_update_ranking', methods: ['POST'])]
    public function updateRanking(Request $request, Uuid $id): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);

            if (!isset($data['ranking']) || !is_scalar($data['ranking'])) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Campo "ranking" é obrigatório.',
                ], JsonResponse::HTTP_BAD_REQUEST);
            }

            $ranking = strtoupper(trim((string) $data['ranking']));

            $this->proposalOrderingService->updateProposalRanking($id, $ranking);

            return new JsonResponse([
                'success' => true,
                'message' => 'Posição atualizada com sucesso.',
                'ranking' => $ranking,
            ]);
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse([
                'success' => false,
                'message' => $exception->getMessage(),
            ], JsonResponse::HTTP_BAD_REQUEST);
        } catch (\Exception $exception) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Erro ao atualizar posição: '.$exception->getMessage(),
            ], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[IsGranted(UserRolesEnum::ROLE_ADMIN->value, statusCode: self::ACCESS_DENIED_RESPONSE_CODE)]
    #[Route('/painel/admin/propostas/{id}/deletar', name: 'admin_regmel_proposal_soft_delete', methods: ['POST'])]
    public function softDelete(Uuid $id, Request $request): Response
    {
        try {
            $proposal = $this->initiativeService->get($id);
            $proposalStatus = $proposal->getExtraFields()['status'] ?? '';
            $deletionReason = $request->request->get('deletion_reason');

            // Validar se a proposta está anuída ou selecionada
            if ($proposalStatus === StatusProposalEnum::ANUIDA->value || StatusProposalEnum::isSelected($proposalStatus)) {
                $this->addFlash('error', $this->translator->trans('view.proposal.error.cannot_delete_anuida_selecionada'));

                return $this->redirectToRoute('admin_regmel_proposal_list');
            }

            // Validação do motivo
            if (empty($deletionReason) || strlen($deletionReason) < 20) {
                $this->addFlash('error', $this->translator->trans('view.proposal.error.deletion_reason_required'));

                return $this->redirectToRoute('admin_regmel_proposal_list');
            }

            // Realizar soft delete
            $this->proposalService->softDeleteProposal($id, $deletionReason);

            $this->addFlashSuccess($this->translator->trans('view.proposal.message.soft_deleted'));
        } catch (Exception $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_regmel_proposal_list');
    }

    #[IsGranted(UserRolesEnum::ROLE_ADMIN->value, statusCode: self::ACCESS_DENIED_RESPONSE_CODE)]
    #[Route('/painel/admin/propostas/{id}/remover', name: 'admin_regmel_proposal_remove', methods: ['GET'])]
    public function remove(Uuid $id): Response
    {
        try {
            $proposal = $this->initiativeService->get($id);
            $proposalStatus = $proposal->getExtraFields()['status'] ?? '';

            // Verificar se a proposta tem status "Anuída" ou "Selecionada"
            if ($proposalStatus === StatusProposalEnum::ANUIDA->value || StatusProposalEnum::isSelected($proposalStatus)) {
                $this->addFlash('error', $this->translator->trans('view.proposal.error.cannot_delete_approved'));

                return $this->redirectToRoute('admin_dashboard');
            }

            $this->initiativeService->remove($id);

            // Limpar cache do Doctrine para forçar recalcular no dashboard
            $this->entityManager->clear();

            $this->addFlashSuccess($this->translator->trans('view.proposal.message.deleted'));
        } catch (Exception $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_dashboard');
    }
}
