<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\OrganizationDto;
use App\Entity\Organization;
use App\Enum\OrganizationTypeEnum;
use App\Enum\StatusProposalEnum;
use App\Exception\Organization\OrganizationResourceNotFoundException;
use App\Exception\ValidatorException;
use App\Repository\Interface\OrganizationRepositoryInterface;
use App\Service\Interface\AgentServiceInterface;
use App\Service\Interface\FileServiceInterface;
use App\Service\Interface\OrganizationServiceInterface;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

readonly class OrganizationService extends AbstractEntityService implements OrganizationServiceInterface
{
    private const string DIR_ORGANIZATION_PROFILE = 'app.dir.organization.profile';

    public function __construct(
        private FileServiceInterface $fileService,
        private ParameterBagInterface $parameterBag,
        private OrganizationRepositoryInterface $repository,
        private Security $security,
        private SerializerInterface $serializer,
        private ValidatorInterface $validator,
        private EntityManagerInterface $entityManager,
        private AgentServiceInterface $agentService,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
    ) {
        parent::__construct(
            $this->security,
            $this->serializer,
            $this->validator,
            $this->entityManager,
            Organization::class,
            $this->fileService,
            $this->parameterBag,
            self::DIR_ORGANIZATION_PROFILE,
        );
    }

    public function count(): int
    {
        return $this->repository->count(
            $this->getDefaultParams()
        );
    }

    public function create(array $organization): Organization
    {
        error_log('OrganizationService::create chamado com: ' . json_encode($organization));
        
        // Se não informar owner ou createdBy, usar o primeiro usuário disponível
        if (empty($organization['owner'])) {
            $firstUser = $this->entityManager->getRepository('App\Entity\Agent')->findOneBy([]);
            if ($firstUser) {
                $organization['owner'] = $firstUser->getId()->toRfc4122();
            }
        }
        
        if (empty($organization['createdBy'])) {
            $firstUser = $this->entityManager->getRepository('App\Entity\Agent')->findOneBy([]);
            if ($firstUser) {
                $organization['createdBy'] = $firstUser->getId()->toRfc4122();
            }
        }
        
        $organization = $this->validateInput($organization, OrganizationDto::class, OrganizationDto::CREATE);

        $organizationObj = $this->serializer->denormalize($organization, Organization::class);

        $savedOrganization = $this->repository->save($organizationObj);

        error_log('Organização salva: ' . $savedOrganization->getName() . ' (tipo: ' . $savedOrganization->getType() . ')');

        // Se é um município (MUNICIPIO), associar propostas órfãs com o mesmo nome
        if ($savedOrganization->getType() === OrganizationTypeEnum::MUNICIPIO->value) {
            error_log('Tipo é MUNICIPIO, chamando reassociateProposalsForMunicipality');
            $this->reassociateProposalsForMunicipality($savedOrganization);
        } else {
            error_log('Tipo NÃO é MUNICIPIO: ' . $savedOrganization->getType());
        }

        return $savedOrganization;
    }

    public function findBy(array $params = [], int $limit = 4000): array
    {
        return $this->repository->findBy(
            [...$params, ...$this->getUserParams()],
            ['createdAt' => 'DESC'],
            $limit
        );
    }

    public function findOneBy(array $params): ?Organization
    {
        return $this->repository->findOneBy(
            [...$params, ...$this->getDefaultParams()]
        );
    }

    public function get(Uuid $id): Organization
    {
        $organization = $this->repository->findOneBy([
            ...['id' => $id],
            ...$this->getDefaultParams(),
        ]);

        if (null === $organization) {
            throw new OrganizationResourceNotFoundException();
        }

        return $organization;
    }

    public function list(int $limit = 50, array $params = [], string $order = 'DESC'): array
    {
        return $this->repository->findBy(
            [...$params, ...$this->getDefaultParams()],
            ['createdAt' => $order],
            $limit
        );
    }

    public function remove(Uuid $id): void
    {
        $organization = $this->findOneBy(
            [...['id' => $id], ...$this->getUserParams()]
        );

        if (null === $organization) {
            throw new OrganizationResourceNotFoundException();
        }

        $organization->setDeletedAt(new DateTime());

        if ($organization->getImage()) {
            $this->fileService->deleteFileByUrl($organization->getImage());
        }

        $this->repository->save($organization);
    }

    public function update(Uuid $identifier, array $organization): Organization
    {
        $organizationFromDB = $this->get($identifier);

        $organizationDto = $this->validateInput($organization, OrganizationDto::class, OrganizationDto::UPDATE);

        $organizationObj = $this->serializer->denormalize($organizationDto, Organization::class, context: [
            'object_to_populate' => $organizationFromDB,
        ]);

        $organizationObj->setUpdatedAt(new DateTime());

        return $this->repository->save($organizationObj);
    }

    public function updateImage(Uuid $id, UploadedFile $uploadedFile): Organization
    {
        $organization = $this->get($id);

        $organizationDto = new OrganizationDto();
        $organizationDto->image = $uploadedFile;

        $violations = $this->validator->validate($organizationDto, groups: [OrganizationDto::UPDATE]);

        if ($violations->count() > 0) {
            throw new ValidatorException(violations: $violations);
        }

        if ($organization->getImage()) {
            $this->fileService->deleteFileByUrl($organization->getImage());
        }

        $uploadedImage = $this->fileService->uploadImage(
            $this->parameterBag->get(self::DIR_ORGANIZATION_PROFILE),
            $uploadedFile
        );

        $relativePath = '/uploads'.$this->parameterBag->get(self::DIR_ORGANIZATION_PROFILE).'/'.$uploadedImage->getFilename();
        $organization->setImage($relativePath);

        $organization->setUpdatedAt(new DateTime());

        $this->repository->save($organization);

        return $organization;
    }

    public function getMunicipalitiesByAgents(iterable $agents): array
    {
        return $this->repository->findMunicipalitiesByAgents($agents);
    }

    public function getCompaniesByAgents(iterable $agents): array
    {
        return $this->repository->findCompaniesByAgents($agents);
    }

    public function removeAgent(Uuid $agentId, Uuid $organizationId): void
    {
        $organization = $this->get($organizationId);
        $agent = $this->agentService->get($agentId);

        $organization->removeAgent($agent);
        $this->repository->save($organization);
    }

    public function findByMunicipalityFilters(string $region, ?string $state): array
    {
        return $this->repository->findOrganizationByRegionAndState($region, $state);
    }

    public function findByCompanyFilters(string $tipo): array
    {
        return $this->repository->findOrganizationByCompanyFilters($tipo);
    }

    public function hardDelete(Uuid $id): void
    {
        $organization = $this->get($id);

        // Salvar o owner_id ANTES de deletar a organização
        $ownerId = $organization->getOwner()?->getId();

        // Remove a imagem se existir
        if ($organization->getImage()) {
            $this->fileService->deleteFileByUrl($organization->getImage());
        }

        // Desassocia as propostas (initiatives) do município e volta para estado de proposta criada
        // (como se fosse uma proposta para um município ainda não cadastrado)
        $sql = "UPDATE initiative 
                SET organization_to_id = NULL, 
                    extra_fields = jsonb_set(extra_fields::jsonb, '{status}', :statusValue)::json
                WHERE organization_to_id = :organization_id";
        $affectedRows = $this->entityManager->getConnection()->executeStatement($sql, [
            'organization_id' => $id->toRfc4122(),
            'statusValue' => json_encode(StatusProposalEnum::SEM_ADESAO->value),
        ]);
        
        error_log("🗑️ HARDDELETE: {$affectedRows} propostas desassociadas do município e voltadas para 'Sem Adesão do Município'");

        // Remove todas as inscrições de fases (inscription_phase) vinculadas a esta organização
        $sql = 'DELETE FROM inscription_phase WHERE organization_id = :organization_id';
        $this->entityManager->getConnection()->executeStatement($sql, [
            'organization_id' => $id->toRfc4122(),
        ]);

        // Remove todas as inscrições (inscription_opportunity) vinculadas a esta organização
        $sql = 'DELETE FROM inscription_opportunity WHERE organization_id = :organization_id';
        $this->entityManager->getConnection()->executeStatement($sql, [
            'organization_id' => $id->toRfc4122(),
        ]);

        // Remove associações entre agentes e a organização (para quem tiver múltiplas organizações)
        $sql = 'DELETE FROM organizations_agents WHERE organization_id = :organization_id';
        $this->entityManager->getConnection()->executeStatement($sql, [
            'organization_id' => $id->toRfc4122(),
        ]);

        // DELETA A ORGANIZAÇÃO PRIMEIRO (antes de deletar agentes)
        $this->repository->hardDelete($id);

        // DEPOIS deleta o owner (administrador) da organização deletada
        if ($ownerId) {
            // Primeiro busca o agent para obter o user_id
            $agentWithUser = $this->entityManager->getConnection()->fetchAssociative(
                'SELECT user_id FROM agent WHERE id = ?',
                [$ownerId->toRfc4122()]
            );
            
            // Deleta o agent
            $this->entityManager->getConnection()->executeStatement(
                'DELETE FROM agent WHERE id = ?',
                [$ownerId->toRfc4122()]
            );
            
            // Deleta o user associado ao agent (se existir)
            if ($agentWithUser && $agentWithUser['user_id']) {
                $this->entityManager->getConnection()->executeStatement(
                    'DELETE FROM app_user WHERE id = ?',
                    [$agentWithUser['user_id']]
                );
            }
        }

        // Deleta os agentes (usuários) que estavam vinculados APENAS a esta organização
        $sql = '
            DELETE FROM agent 
            WHERE id NOT IN (
                SELECT DISTINCT agent_id FROM organizations_agents
            )
            AND id IN (
                SELECT DISTINCT agent_id FROM organizations_agents 
                WHERE organization_id = :organization_id
            )
        ';
        $this->entityManager->getConnection()->executeStatement($sql, [
            'organization_id' => $id->toRfc4122(),
        ]);
    }

    /**
     * Reassocia propostas órfãs para o município quando ele é recriado.
     * Procura por propostas órfãs que tinham um nome de município armazenado em extraFields['city_name']
     * e as associa ao novo município baseado no match do nome.
     */
    private function reassociateProposalsForMunicipality(Organization $municipality): void
    {
        if ($municipality->getType() !== OrganizationTypeEnum::MUNICIPIO->value) {
            return;
        }

        try {
            $municipalityId = $municipality->getId()->toRfc4122();
            $municipalityName = $municipality->getName();

            error_log("🔍 REASSOCIAR: Procurando propostas órfãs para município '{$municipalityName}' (ID: {$municipalityId})");

            // Busca o termo_status do novo município criado
            $municipalityTermStatus = $municipality->getExtraFields()['term_status'] ?? null;
            $newStatus = match ($municipalityTermStatus) {
                'approved' => StatusProposalEnum::RECEBIDA->value,
                'rejected', 'awaiting' => StatusProposalEnum::ENVIADA->value,
                default => StatusProposalEnum::SEM_ADESAO->value,
            };

            // SQL para reassociar propostas órfãs que têm um nome de município matching
            // Apenas reassocia propostas que estão em SEM_ADESAO (estado de proposta criada sem município)
            // Isso garante que propostas em outros estados não sejam alteradas
            $sql = "
                UPDATE initiative 
                SET organization_to_id = :municipalityId,
                    extra_fields = jsonb_set(extra_fields::jsonb, '{status}', :newStatus)::json
                WHERE organization_to_id IS NULL
                AND extra_fields::jsonb ->> 'status' = :statusFilter
                AND (
                    extra_fields::jsonb ->> 'city_name' LIKE :pattern
                    OR extra_fields::jsonb ->> 'city_name' = :exactName
                )
            ";

            $affectedRows = $this->entityManager->getConnection()->executeStatement($sql, [
                'municipalityId' => $municipalityId,
                'newStatus' => json_encode($newStatus),
                'statusFilter' => StatusProposalEnum::SEM_ADESAO->value,
                'pattern' => $municipalityName . '-%',  // São Paulo-SP, São Paulo-RJ, etc
                'exactName' => $municipalityName,       // Ou match exato
            ]);
            
            error_log("✅ REASSOCIAR: {$affectedRows} propostas órfãs foram reassociadas para '{$municipalityName}' com status '{$newStatus}'");
        } catch (\Exception $e) {
            error_log("❌ ERRO ao reassociar propostas: " . $e->getMessage());
            return;
        }
    }

    public function getCsvHeaders(?string $type): array
    {
        if ($type === OrganizationTypeEnum::MUNICIPIO->value) {
            return [
                'ID',
                'Código da Cidade',
                'Nome',
                'Descrição',
                'Região',
                'Estado',
                'Status do Termo de Adesão',
                'Termo de Adesão',
                'Versão do Termo de Adesão',
                'Email',
                'Telefone',
                'Site',
                'Experiência Habitacional',
                'Possui PLHIS',
                'Criado Por',
                'Criado Em',
            ];
        }

        return [
            'Nome da Organização',
            'Tipo',
            'Enquadramento',
            'CNPJ',
            'E-mail',
            'Site',
            'Telefone',
            'Responsável',
            'Criado em',
            'Criado por',
        ];
    }

    public function getCsvRow(object $entity, ?string $type): array
    {
        if (!$entity instanceof Organization) {
            throw new InvalidArgumentException('Expected Organization entity.');
        }

        if ($type === OrganizationTypeEnum::MUNICIPIO->value) {
            $extraFields = $entity->getExtraFields();

            $formExists = isset($extraFields['form']);
            $documentLink = $formExists
                ? $this->urlGenerator->generate(
                    'regmel_municipality_document_file',
                    ['id' => $entity->getId()],
                    UrlGeneratorInterface::ABSOLUTE_URL
                )
                : '';
            $status = match ($extraFields['term_status'] ?? '') {
                'awaiting' => $this->translator->trans('awaiting'),
                'approved' => $this->translator->trans('accepted'),
                'rejected' => $this->translator->trans('rejected'),
                default => $this->translator->trans('unknown'),
            };

            return [
                $entity->getId(),
                $extraFields['cityCode'] ?? '',
                $entity->getName(),
                $entity->getDescription(),
                $extraFields['region'] ?? '',
                $extraFields['state'] ?? '',
                $status ?? '',
                $documentLink,
                $extraFields['term_version'] ?? '',
                $extraFields['email'] ?? '',
                $extraFields['telefone'] ?? '',
                $extraFields['site'] ?? '',
                isset($extraFields['hasHousingExperience'])
                    ? ($extraFields['hasHousingExperience'] ? 'Sim' : 'Não')
                    : '',
                isset($extraFields['hasPlhis'])
                    ? ($extraFields['hasPlhis'] ? 'Sim' : 'Não')
                    : '',
                $entity->getCreatedBy() ? $entity->getCreatedBy()->getName() : '-',
                $entity->getCreatedAt()->format('d/m/Y H:i:s'),
            ];
        }

        return [
            $entity->getName(),
            $entity->getExtraFields()['tipo'] ?? '',
            $entity->getExtraFields()['framework'] ?? '',
            $entity->getExtraFields()['cnpj'] ?? '',
            $entity->getExtraFields()['email'] ?? '',
            $entity->getExtraFields()['site'] ?? '',
            $entity->getExtraFields()['telefone'] ?? '',
            $entity->getOwner()->getName() ?? '',
            $entity->getCreatedAt()->format('d/m/Y H:i:s'),
            $entity->getCreatedBy() ? $entity->getCreatedBy()->getName() : '-',
        ];
    }

    public function findByCnpj(string $cnpj, ?string $excludeId = null): ?Organization
    {
        return $this->repository->findByCnpj($cnpj, $excludeId);
    }
}
