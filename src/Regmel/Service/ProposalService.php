<?php

declare(strict_types=1);

namespace App\Regmel\Service;

use App\Entity\Initiative;
use App\Entity\InscriptionOpportunity;
use App\Entity\InscriptionPhase;
use App\Entity\Opportunity;
use App\Entity\Organization;
use App\Enum\InscriptionOpportunityStatusEnum;
use App\Enum\InscriptionPhaseStatusEnum;
use App\Enum\OrganizationTypeEnum;
use App\Enum\StatusProposalEnum;
use App\Environment\ConfigEnvironment;
use App\Exception\UnableCreateFileException;
use App\Regmel\Repository\Interface\ProposalRepositoryInterface;
use App\Regmel\Service\Interface\ProposalServiceInterface;
use App\Repository\Interface\InitiativeRepositoryInterface;
use App\Repository\OrganizationRepository;
use App\Service\AbstractEntityService;
use App\Service\InitiativeService;
use App\Service\Interface\CityServiceInterface;
use App\Service\Interface\EmailServiceInterface;
use App\Service\Interface\FileServiceInterface;
use App\Service\Interface\OrganizationServiceInterface;
use App\Service\OpportunityService;
use App\Service\PhaseService;
use App\Service\UserService;
use DateTime;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;
use ZipArchive;

readonly class ProposalService extends AbstractEntityService implements ProposalServiceInterface
{
    public function __construct(
        private FileServiceInterface $fileService,
        private ParameterBagInterface $parameterBag,
        private InitiativeRepositoryInterface $initiativeRepository,
        private OrganizationRepository $organizationRepository,
        private OpportunityService $opportunityService,
        private CityServiceInterface $cityService,
        private EntityManagerInterface $entityManager,
        private PhaseService $phaseService,
        protected TokenStorageInterface $tokenStorage,
        private Security $security,
        private InitiativeService $initiativeService,
        private UserService $userService,
        private SerializerInterface $serializer,
        private OrganizationServiceInterface $organizationService,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
        private ConfigEnvironment $configEnvironment,
        private EmailServiceInterface $emailService,
        private ProposalRepositoryInterface $proposalRepository,
    ) {
        parent::__construct(
            $this->security,
            $this->serializer,
            null,
            $this->entityManager,
            Initiative::class,
            $this->fileService,
            $this->parameterBag,
        );
    }

    public function saveProposal(
        Organization $company,
        array $data,
        ?UploadedFile $map = null,
        ?UploadedFile $project = null,
        ?UploadedFile $annexIvC = null,
        ?UploadedFile $technicalManager = null,
        ?UploadedFile $rrtArt = null
    ): Initiative {
        $user = $this->security->getUser();

        $municipality = $this->organizationRepository->findOrganizationByCityId($data['city']);

        $municipalityTermStatus = $municipality?->getExtraFields()['term_status'] ?? null;
        $status = match ($municipalityTermStatus) {
            'approved' => StatusProposalEnum::RECEBIDA->value,
            'rejected', 'awaiting' => StatusProposalEnum::ENVIADA->value,
            default => StatusProposalEnum::SEM_ADESAO->value,
        };

        $initiative = new Initiative();
        $initiative->setId(Uuid::v4());
        $initiative->setName($data['name']);
        $initiative->setCreatedBy($user->getAgents()->first());
        $initiative->setCreatedAt(new DateTimeImmutable());
        $initiative->setOrganizationFrom($company);
        $initiative->setOrganizationTo($municipality);

        if (null === $municipality) {
            $municipality = $this->cityService->get($data['city']);
            $state = $municipality->getState()->getAcronym();
            $cityId = $municipality->getId();
            $cityCode = $municipality->getCityCode();
            $cityName = $municipality->getName().'-'.$state;
            $region = $municipality->getState()->getRegion();
        } else {
            $state = $municipality->getExtraFields()['state'];
            $cityId = $municipality->getExtraFields()['cityId'];
            $cityCode = $municipality->getExtraFields()['cityCode'] ?? '';
            $cityName = $municipality->getName().'-'.$state;
            $region = $municipality->getExtraFields()['region'];
        }

        $mapFileName = null;
        $projectFileName = null;
        $annexIvCFileName = null;
        $technicalManagerFileName = null;
        $rrtArtFileName = null;

        $inscriptionNumber = substr($initiative->getId()->toRfc4122(), 0, 8);

        if (null !== $map) {
            $mapFileName = $this->uploadFile($map, $inscriptionNumber, $cityCode, $state, $cityName, $company->getName(), '01', 'area-poligonal');
        }

        if (null !== $project) {
            $projectFileName = $this->uploadFile($project, $inscriptionNumber, $cityCode, $state, $cityName, $company->getName(), '01', 'projeto');
        }

        if (null !== $annexIvC) {
            $annexIvCFileName = $this->uploadFile($annexIvC, $inscriptionNumber, $cityCode, $state, $cityName, $company->getName(), '01', 'anexo-iv-c');
        }

        if (null !== $technicalManager) {
            $technicalManagerFileName = $this->uploadFile($technicalManager, $inscriptionNumber, $cityCode, $state, $cityName, $company->getName(), '01', 'responsavel-tecnico');
        }

        if (null !== $rrtArt) {
            $rrtArtFileName = $this->uploadFile($rrtArt, $inscriptionNumber, $cityCode, $state, $cityName, $company->getName(), '01', 'rrt-art');
        }

        $initiative->setExtraFields([
            'status' => $status,
            'area_size' => (float) $data['area_size'],
            'quantity_houses' => (float) $data['quantity_houses'],
            'area_characteristic' => $data['area_characteristic'],
            'map_file' => $mapFileName,
            'project_file' => $projectFileName,
            'annex_iv_c_file' => $annexIvCFileName,
            'technical-manager_file' => $technicalManagerFileName,
            'rrt_art_file' => $rrtArtFileName,
            'city_code' => $cityCode,
            'city_name' => $cityName,
            'city_id' => $cityId,
            'state' => $state,
            'region' => $region,
            'anticipation' => 'false',
            'snpr_affiliation' => $data['snpr_affiliation'] ?? 'Não',
            'snpr_affiliation_details' => $data['snpr_affiliation_details'] ?? '',
            'zipcode' => $data['zipcode'] ?? '',
            'address' => $data['address'] ?? '',
        ]);

        $this->initiativeRepository->save($initiative);

        return $initiative;
    }

    public function findOpportunitiesBy(OrganizationTypeEnum $enum): array
    {
        $opportunities = $this->opportunityService->findBy();

        return array_filter(
            $opportunities,
            fn (Opportunity $opportunity) => ($opportunity->getExtraFields()['type'] ?? null) === $enum->value
        );
    }

    private function createInscriptionForOrganization(string $opportunityId, Organization $organization): void
    {
        $opportunityId = Uuid::fromString($opportunityId);
        $opportunity = $this->opportunityService->get($opportunityId);

        $inscription = new InscriptionOpportunity();
        $inscription->setOpportunity($opportunity);
        $inscription->setOrganization($organization);
        $inscription->setId(Uuid::v4());
        $inscription->setStatus(InscriptionOpportunityStatusEnum::ACTIVE->value);

        $firstPhase = current($this->phaseService->list($opportunityId, 1));

        if (false !== $firstPhase) {
            $inscriptionPhase = new InscriptionPhase();
            $inscriptionPhase->setId(Uuid::v4());
            $inscriptionPhase->setOrganization($inscription->getOrganization());
            $inscriptionPhase->setPhase($firstPhase);
            $inscriptionPhase->setStatus(InscriptionPhaseStatusEnum::ACTIVE->value);

            $this->entityManager->persist($inscriptionPhase);
        }

        $this->entityManager->persist($inscription);
        $this->entityManager->flush();
    }

    private function uploadFile(
        UploadedFile $uploadedFile,
        string $inscriptionNumber,
        string|int $cityCode,
        string $state,
        string $municipality,
        string $company,
        string $version = '01',
        string $extraName = '',
    ): string {
        $pdf = $this->fileService->uploadMixedFile(
            $uploadedFile,
            '/regmel/company/documents',
            "{$inscriptionNumber}-{$cityCode}-{$state}-{$municipality}-{$extraName}-{$company}-{$version}",
        );

        return $pdf->getFilename();
    }

    public function isDuplicateOrganization(string $name, string $cityId): bool
    {
        return $this->organizationRepository->isOrganizationDuplicate($name, $cityId);
    }

    public function generateProposalCode(Initiative $proposal): string
    {
        return substr($proposal->getId()->toRfc4122(), 0, 8);
    }

    private function getAgreementStatusLabel(?string $status): string
    {
        return match ($status) {
            'submitted' => 'Aguardando Validação',
            'approved' => 'Aprovado',
            'rejected' => 'Rejeitado',
            default => '',
        };
    }

    private function generateUrlForField(Initiative $entity, string $fieldName, string $routeName): string
    {
        $extraFields = $entity->getExtraFields();

        return isset($extraFields[$fieldName])
            ? $this->urlGenerator->generate($routeName, ['id' => $entity->getId()], UrlGeneratorInterface::ABSOLUTE_URL)
            : '';
    }

    public function getCsvHeaders(?string $type = null): array
    {
        return [
            'Posição',
            $this->translator->trans('csv.header.id'),
            $this->translator->trans('csv.header.region'),
            $this->translator->trans('csv.header.state'),
            $this->translator->trans('csv.header.city'),
            $this->translator->trans('csv.header.city_code'),
            $this->translator->trans('csv.header.intervention_area_name'),
            $this->translator->trans('csv.header.houses_quantity'),
            $this->translator->trans('csv.header.total_area'),
            $this->translator->trans('csv.header.total_value'),
            $this->translator->trans('csv.header.proposal_status'),
            $this->translator->trans('csv.header.map_file'),
            $this->translator->trans('csv.header.project_file'),
            $this->translator->trans('csv.header.company_name'),
            $this->translator->trans('csv.header.company_cnpj'),
            'Tipo Instituição',
            $this->translator->trans('csv.header.company_email'),
            $this->translator->trans('csv.header.company_phone'),
            'Agente Promotor Nome representante AP',
            'CPF do representante AP',
            'E-mail representante AP',
            'Telefone representante AP',
            $this->translator->trans('csv.header.proposal_date'),
        ];
    }

    public function getCsvRow(object $entity, ?string $type = null): array
    {
        if (!$entity instanceof Initiative) {
            throw new InvalidArgumentException($this->translator->trans('error.invalid_entity'));
        }

        $variables = (new ConfigEnvironment())->aurora()['variables'];
        $pricePerHousehold = $variables['price_per_household'];
        $extraFields = $entity->getExtraFields();
        $organizationFrom = $entity->getOrganizationFrom();
        $housesQuantity = (float) ($extraFields['quantity_houses'] ?? 0);
        $totalValue = $housesQuantity * $pricePerHousehold;

        $mapFileLink = $this->generateUrlForField($entity, 'map_file', 'admin_regmel_proposal_mapa');
        $projectFileLink = $this->generateUrlForField($entity, 'project_file', 'admin_regmel_proposal_projeto');

        $env = $this->configEnvironment->aurora();
        $region = $extraFields['region'] ?? '';
        $state = $extraFields['state'] ?? '';
        $phoneCompany = $organizationFrom->getExtraFields()['telefone'] ?? $organizationFrom->getExtraFields()['phone'] ?? '';

        // Buscar dados do representante (usuário owner da organização)
        $representativeName = '';
        $representativeCpf = '';
        $representativeEmail = '';
        $representativePhone = '';
        
        if ($organizationFrom) {
            try {
                $agent = $organizationFrom->getOwner();
                if ($agent) {
                    $owner = $agent->getUser();
                    if ($owner) {
                        $representativeName = trim(($owner->getFirstname() ?? '') . ' ' . ($owner->getLastname() ?? ''));
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

        // Posição: evaluation_ranking para SELECIONADA e CLASSIFICADA
        $position = '';
        $currentStatus = $extraFields['status'] ?? '';
        if ($currentStatus === StatusProposalEnum::SELECIONADA->value || $currentStatus === StatusProposalEnum::CLASSIFICADA->value) {
            $position = $extraFields['evaluation_ranking'] ?? '';
        }

        return [
            $position,
            $this->generateProposalCode($entity),
            $region,
            $state,
            $extraFields['city_name'] ?? '',
            $extraFields['city_code'] ?? $extraFields['cityCode'] ?? '',
            $entity->getName() ?? '',
            $housesQuantity,
            $extraFields['area_size'] ?? 0,
            number_format($totalValue, 2, ',', '.'),
            $extraFields['status'] ?? '',
            $mapFileLink,
            $projectFileLink,
            $organizationFrom->getName(),
            $organizationFrom->getExtraFields()['cnpj'] ?? '',
            $organizationFrom->getExtraFields()['tipo'] ?? 'Não Informado',
            $organizationFrom->getExtraFields()['email'] ?? '',
            $phoneCompany,
            $representativeName,
            $representativeCpf,
            $representativeEmail,
            $representativePhone,
            $entity->getCreatedAt()->format('d/m/Y H:i:s'),
        ];
    }

    public function exportProjectFiles(array $proposals): string
    {
        $zipFilePath = sprintf('%s/storage/regmel/company/documents/export.zip', $this->parameterBag->get('kernel.project_dir'));
        $zip = new ZipArchive();

        if (true !== $zip->open($zipFilePath, ZipArchive::CREATE)) {
            throw new UnableCreateFileException();
        }

        foreach ($proposals as $proposal) {
            if (empty($proposal->getExtraFields()['project_file'])) {
                continue;
            }
            $filePath = $this->getDocumentPath($proposal->getExtraFields()['project_file']);
            if (file_exists($filePath)) {
                $zip->addFile($filePath, basename($filePath));
            }
        }
        $zip->close();

        return $zipFilePath;
    }

    public function exportMapFiles(array $proposals): string
    {
        $zipFilePath = sprintf('%s/storage/regmel/company/documents/export.zip', $this->parameterBag->get('kernel.project_dir'));
        $zip = new ZipArchive();

        if (true !== $zip->open($zipFilePath, ZipArchive::CREATE)) {
            throw new UnableCreateFileException();
        }

        foreach ($proposals as $proposal) {
            if (empty($proposal->getExtraFields()['map_file'])) {
                continue;
            }
            $filePath = $this->getDocumentPath($proposal->getExtraFields()['map_file']);
            if (file_exists($filePath)) {
                $zip->addFile($filePath, basename($filePath));
            }
        }
        $zip->close();

        return $zipFilePath;
    }

    public function exportAnticipationFiles(array $proposals): string
    {
        $zipFilePath = sprintf('%s/storage/regmel/company/documents/anticipation_export.zip', $this->parameterBag->get('kernel.project_dir'));
        $zip = new ZipArchive();

        if (true !== $zip->open($zipFilePath, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
            throw new UnableCreateFileException();
        }

        foreach ($proposals as $proposal) {
            $extra = $proposal->getExtraFields();
            $files = ['annex_iv_c_file', 'technical-manager_file', 'rrt_art_file'];
            foreach ($files as $field) {
                if (!empty($extra[$field])) {
                    $filePath = $this->getDocumentPath($extra[$field]);
                    if (file_exists($filePath)) {
                        $zip->addFile($filePath, $extra[$field]);
                    }
                }
            }
        }
        $zip->close();

        return $zipFilePath;
    }

    private function getDocumentPath(string $file): string
    {
        $path = $this->parameterBag->get('kernel.project_dir');
        return "{$path}/storage/regmel/company/documents/{$file}";
    }

    public function updateStatusProposal(Uuid $id, StatusProposalEnum $status, string $reason): void
    {
        $initiative = $this->initiativeService->get($id);
        $extraFields = $initiative->getExtraFields();
        
        $extraFields['status'] = $status->value;
        $extraFields['status_reason'] = $reason;

        $user = $this->security->getUser();
        if ($user) {
            $extraFields['status_updated_by'] = $user->getId()->toRfc4122();
            $extraFields['status_updated_at'] = (new DateTime())->format('Y-m-d H:i:s');
            // Aqui estamos salvando o nome!
            $extraFields['status_updated_by_name'] = $user->getName();
        }

        $initiative->setExtraFields($extraFields);
        
        $this->initiativeRepository->save($initiative);
        $this->entityManager->flush();

        // Enviar email de notificação
        $municipalityEmails = [];
        $organizationTo = $initiative->getOrganizationTo();
        if ($organizationTo) {
            if ($organizationTo->getAgents()) {
                foreach ($organizationTo->getAgents() as $agent) {
                    if ($agent->getUser()) {
                        $municipalityEmails[] = $agent->getUser()->getEmail();
                    }
                }
            }
            // Adiciona email do município dos extra_fields
            $municipalityEmail = $organizationTo->getExtraFields()['email'] ?? null;
            if ($municipalityEmail) {
                $municipalityEmails[] = $municipalityEmail;
            }
        }

        $companyEmails = [];
        $organizationFrom = $initiative->getOrganizationFrom();
        if ($organizationFrom) {
            if ($organizationFrom->getAgents()) {
                foreach ($organizationFrom->getAgents() as $agent) {
                    if ($agent->getUser()) {
                        $companyEmails[] = $agent->getUser()->getEmail();
                    }
                }
            }
            // Adiciona email da empresa dos extra_fields
            $companyEmail = $organizationFrom->getExtraFields()['email'] ?? null;
            if ($companyEmail) {
                $companyEmails[] = $companyEmail;
            }
        }

        // Se for NAO_ANUIDA, enviar apenas para empresa/OSC. Caso contrário, enviar para ambos
        $allEmails = $status === StatusProposalEnum::NAO_ANUIDA
            ? array_unique(array_filter($companyEmails))
            : array_unique(array_filter([...$municipalityEmails, ...$companyEmails]));

        if (!empty($allEmails)) {
            $this->emailService->sendTemplatedEmail(
                $allEmails,
                'Status da proposta atualizado',
                '_emails/new-proposal-status.html.twig',
                [
                    'municipalityName' => $organizationTo ? $organizationTo->getName() : ($extraFields['city_name'] ?? 'Município'),
                    'companyName' => $organizationFrom ? $organizationFrom->getName() : 'Empresa',
                    'proposalName' => $initiative->getName(),
                    'status' => $status->value,
                    'reason' => $reason,
                ]
            );
        }
    }

    public function bulkUpdateStatus(array $proposals, string $status): void
    {
        $user = $this->security->getUser();
        $userName = $user ? $user->getName() : 'Usuário Desconhecido';
        $this->proposalRepository->bulkUpdateStatus($proposals, $status, $userName);
    }

    public function softDeleteProposal(Uuid $id, string $deletionReason): void
    {
        $proposal = $this->entityManager->getRepository(Initiative::class)->find($id);

        if (!$proposal) {
            throw new InvalidArgumentException($this->translator->trans('error.invalid_entity'));
        }

        $user = $this->security->getUser();

        // Marcar como excluída usando extraFields
        $proposal->setIsDeleted(true);
        $proposal->setDeletedTime(new DateTime());
        $proposal->setDeletedBy($user->getName());
        $proposal->setDeletionReason($deletionReason);

        // Atualizar status da proposta para "Excluída"
        $extraFields = $proposal->getExtraFields() ?? [];
        $extraFields['status'] = 'Excluída';
        $proposal->setExtraFields($extraFields);

        // Persistir as mudanças
        $this->entityManager->persist($proposal);
        $this->entityManager->flush();
    }
}