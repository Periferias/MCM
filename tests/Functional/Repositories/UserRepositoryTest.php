<?php

declare(strict_types=1);

namespace App\Tests\Functional\Repositories;

use App\Entity\Agent;
use App\Entity\Organization;
use App\Entity\User;
use App\Repository\UserRepository;
use DateTime;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

class UserRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private UserRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->repository = $this->entityManager->getRepository(User::class);
    }

    public function testFindAllForExportReturnsOneRowForEachActiveOrganization(): void
    {
        $user = $this->createUser('Ana', 'Exportação');
        $agent = $this->createAgent($user, 'Agente Principal', ['cpf' => '123']);
        $organizationB = $this->createOrganization($agent, 'Organização B', ['cnpj' => '22']);
        $organizationA = $this->createOrganization($agent, 'Organização A', ['cnpj' => '11']);
        $this->flush();

        $rows = $this->rowsForUser($user);

        self::assertCount(2, $rows);
        self::assertSame(
            [
                'user_id',
                'user_name',
                'social_name',
                'email',
                'status',
                'roles',
                'user_created_at',
                'user_updated_at',
                'agent_id',
                'agent_name',
                'agent_extra_fields',
                'organization_id',
                'organization_name',
                'organization_type',
                'organization_extra_fields',
                'owner_id',
            ],
            array_keys($rows[0])
        );
        self::assertSame($user->getId()->toRfc4122(), $rows[0]['user_id']);
        self::assertSame('Ana Exportação', $rows[0]['user_name']);
        self::assertSame('Nome Social', $rows[0]['social_name']);
        self::assertSame($user->getEmail(), $rows[0]['email']);
        self::assertSame('active', $rows[0]['status']);
        self::assertSame(['ROLE_ADMIN'], json_decode($rows[0]['roles'], true, flags: JSON_THROW_ON_ERROR));
        self::assertSame('2026-01-02 03:04:05', $rows[0]['user_created_at']);
        self::assertSame('2026-02-03 04:05:06', $rows[0]['user_updated_at']);
        self::assertSame($agent->getId()?->toRfc4122(), $rows[0]['agent_id']);
        self::assertSame('Agente Principal', $rows[0]['agent_name']);
        self::assertSame(['cpf' => '123'], json_decode($rows[0]['agent_extra_fields'], true, flags: JSON_THROW_ON_ERROR));
        self::assertSame($organizationA->getId()?->toRfc4122(), $rows[0]['organization_id']);
        self::assertSame('Organização A', $rows[0]['organization_name']);
        self::assertSame('empresa', $rows[0]['organization_type']);
        self::assertSame(['cnpj' => '11'], json_decode($rows[0]['organization_extra_fields'], true, flags: JSON_THROW_ON_ERROR));
        self::assertSame($agent->getId()?->toRfc4122(), $rows[0]['owner_id']);
        self::assertSame($organizationB->getId()?->toRfc4122(), $rows[1]['organization_id']);
    }

    public function testFindAllForExportPreservesUserWithoutAgent(): void
    {
        $user = $this->createUser('Bruno', 'Sem Agente');
        $this->flush();

        $rows = $this->rowsForUser($user);

        self::assertCount(1, $rows);
        self::assertNull($rows[0]['agent_id']);
        self::assertNull($rows[0]['agent_name']);
        self::assertNull($rows[0]['agent_extra_fields']);
        self::assertNull($rows[0]['organization_id']);
        self::assertNull($rows[0]['organization_name']);
        self::assertNull($rows[0]['organization_type']);
        self::assertNull($rows[0]['organization_extra_fields']);
        self::assertNull($rows[0]['owner_id']);
    }

    public function testFindAllForExportPreservesAgentWithoutOrganization(): void
    {
        $user = $this->createUser('Carla', 'Sem Organização');
        $agent = $this->createAgent($user, 'Agente Sem Organização');
        $this->flush();

        $rows = $this->rowsForUser($user);

        self::assertCount(1, $rows);
        self::assertSame($agent->getId()?->toRfc4122(), $rows[0]['agent_id']);
        self::assertNull($rows[0]['organization_id']);
    }

    public function testFindAllForExportReturnsOneRowForEachAgentOfSameUser(): void
    {
        $user = $this->createUser('Daniela', 'Dois Agentes');
        $agentB = $this->createAgent($user, 'Agente B');
        $agentA = $this->createAgent($user, 'Agente A');
        $this->flush();

        $rows = $this->rowsForUser($user);

        self::assertCount(2, $rows);
        self::assertSame($agentA->getId()?->toRfc4122(), $rows[0]['agent_id']);
        self::assertSame($agentB->getId()?->toRfc4122(), $rows[1]['agent_id']);
    }

    public function testFindAllForExportExcludesSoftDeletedUser(): void
    {
        $user = $this->createUser('Elisa', 'Removida');
        $user->setDeletedAt(new DateTime('2026-03-04 05:06:07'));
        $this->flush();

        self::assertSame([], $this->rowsForUser($user));
    }

    public function testFindAllForExportIgnoresSoftDeletedAgentAndPreservesUser(): void
    {
        $user = $this->createUser('Fabiana', 'Agente Removido');
        $agent = $this->createAgent($user, 'Agente Removido');
        $agent->setDeletedAt(new DateTime('2026-03-04 05:06:07'));
        $this->flush();

        $rows = $this->rowsForUser($user);

        self::assertCount(1, $rows);
        self::assertNull($rows[0]['agent_id']);
        self::assertNull($rows[0]['organization_id']);
    }

    public function testFindAllForExportIgnoresSoftDeletedOrganizationWithoutExtraEmptyRow(): void
    {
        $user = $this->createUser('Gabriela', 'Organização Removida');
        $agent = $this->createAgent($user, 'Agente com Organizações');
        $activeOrganization = $this->createOrganization($agent, 'Organização Ativa');
        $deletedOrganization = $this->createOrganization($agent, 'Organização Removida');
        $deletedOrganization->setDeletedAt(new DateTime('2026-03-04 05:06:07'));
        $this->flush();

        $rows = $this->rowsForUser($user);

        self::assertCount(1, $rows);
        self::assertSame($agent->getId()?->toRfc4122(), $rows[0]['agent_id']);
        self::assertSame($activeOrganization->getId()?->toRfc4122(), $rows[0]['organization_id']);
    }

    private function createUser(string $firstname, string $lastname): User
    {
        $id = Uuid::v4();
        $user = new User();
        $user->setId($id);
        $user->setFirstname($firstname);
        $user->setLastname($lastname);
        $user->setSocialName('Nome Social');
        $user->setEmail(sprintf('export-%s@example.test', $id->toRfc4122()));
        $user->setPassword('not-used');
        $user->setStatus('active');
        $user->setRoles(['ROLE_ADMIN']);
        $user->setCreatedAt(new DateTimeImmutable('2026-01-02 03:04:05'));
        $user->setUpdatedAt(new DateTime('2026-02-03 04:05:06'));

        $this->entityManager->persist($user);

        return $user;
    }

    private function createAgent(User $user, string $name, ?array $extraFields = null): Agent
    {
        $agent = new Agent();
        $agent->setId(Uuid::v4());
        $agent->setName($name);
        $agent->setShortBio('Bio curta');
        $agent->setLongBio('Bio longa');
        $agent->setCulture(false);
        $agent->setMain(false);
        $agent->setExtraFields($extraFields);
        $agent->setUser($user);

        $this->entityManager->persist($agent);

        return $agent;
    }

    private function createOrganization(Agent $agent, string $name, ?array $extraFields = null): Organization
    {
        $organization = new Organization();
        $organization->setId(Uuid::v4());
        $organization->setName($name);
        $organization->setType('empresa');
        $organization->setOwner($agent);
        $organization->setCreatedBy($agent);
        $organization->setExtraFields($extraFields);
        $organization->addAgent($agent);

        $this->entityManager->persist($organization);

        return $organization;
    }

    private function flush(): void
    {
        $this->entityManager->flush();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rowsForUser(User $user): array
    {
        return array_values(array_filter(
            $this->repository->findAllForExport(),
            static fn (array $row): bool => $row['user_id'] === $user->getId()->toRfc4122()
        ));
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->entityManager->close();
        unset($this->entityManager);
    }
}
