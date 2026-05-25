<?php

declare(strict_types=1);

namespace App\Tests\Functional\Services;

use App\Entity\Agent;
use App\Entity\Organization;
use App\Entity\User;
use App\Enum\OrganizationTypeEnum;
use App\Service\OrganizationService;
use DateTime;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

class OrganizationServiceTest extends KernelTestCase
{
    private OrganizationService $organizationService;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->organizationService = self::getContainer()->get(OrganizationService::class);
    }

    public function testCompanyExportIncludesCompleteRegistrationData(): void
    {
        $ownerUser = $this->createUser('responsavel@example.com');
        $owner = $this->createAgent('Maria Responsavel', $ownerUser, [
            'cpf' => '123.456.789-00',
            'cargo' => 'Diretora',
            'telefone' => '(11) 90000-0000',
        ]);

        $memberUser = $this->createUser('membro@example.com');
        $member = $this->createAgent('Joao Membro', $memberUser);

        $createdBy = $this->createAgent('Ana Criadora', $this->createUser('criadora@example.com'));

        $organization = new Organization();
        $organization->setId(Uuid::fromString('11111111-1111-4111-8111-111111111111'));
        $organization->setName('Empresa Completa');
        $organization->setDescription('Descricao completa');
        $organization->setType(OrganizationTypeEnum::EMPRESA->value);
        $organization->setImage('/uploads/company.png');
        $organization->setOwner($owner);
        $organization->setCreatedBy($createdBy);
        $organization->setCreatedAt(new DateTimeImmutable('2026-05-20 10:30:00'));
        $organization->setUpdatedAt(new DateTime('2026-05-21 11:45:00'));
        $organization->setExtraFields([
            'tipo' => 'OSC',
            'framework' => 'sem_fins_lucrativos',
            'cnpj' => '12.345.678/0001-90',
            'email' => 'empresa@example.com',
            'site' => 'https://empresa.example.com',
            'telefone' => '(11) 3333-4444',
            'campo_customizado' => 'valor',
        ]);
        $organization->addSocialNetwork('instagram', '@empresa');
        $organization->addAgent($owner);
        $organization->addAgent($member);

        $headers = $this->organizationService->getCsvHeaders(OrganizationTypeEnum::EMPRESA->value);
        $row = $this->organizationService->getCsvRow($organization, OrganizationTypeEnum::EMPRESA->value);

        $this->assertSame([
            'ID',
            'Nome da Organização',
            'Descrição',
            'Tipo',
            'Tipo Interno',
            'Enquadramento',
            'CNPJ',
            'E-mail',
            'Site',
            'Telefone',
            'Responsável',
            'E-mail do Responsável',
            'CPF do Responsável',
            'Cargo do Responsável',
            'Telefone do Responsável',
            'Membros',
            'Criado em',
            'Atualizado em',
            'Criado por',
            'Imagem',
            'Redes Sociais',
            'Campos Extras',
        ], $headers);

        $this->assertSame('11111111-1111-4111-8111-111111111111', $row[0]);
        $this->assertSame('Empresa Completa', $row[1]);
        $this->assertSame('Descricao completa', $row[2]);
        $this->assertSame(OrganizationTypeEnum::EMPRESA->value, $row[3]);
        $this->assertSame('OSC', $row[4]);
        $this->assertSame('sem_fins_lucrativos', $row[5]);
        $this->assertSame('12.345.678/0001-90', $row[6]);
        $this->assertSame('empresa@example.com', $row[7]);
        $this->assertSame('https://empresa.example.com', $row[8]);
        $this->assertSame('(11) 3333-4444', $row[9]);
        $this->assertSame('Maria Responsavel', $row[10]);
        $this->assertSame('responsavel@example.com', $row[11]);
        $this->assertSame('123.456.789-00', $row[12]);
        $this->assertSame('Diretora', $row[13]);
        $this->assertSame('(11) 90000-0000', $row[14]);
        $this->assertSame('Maria Responsavel <responsavel@example.com>; Joao Membro <membro@example.com>', $row[15]);
        $this->assertSame('20/05/2026 10:30:00', $row[16]);
        $this->assertSame('21/05/2026 11:45:00', $row[17]);
        $this->assertSame('Ana Criadora', $row[18]);
        $this->assertSame('/uploads/company.png', $row[19]);
        $this->assertSame('{"instagram":"@empresa"}', $row[20]);
        $this->assertStringContainsString('"campo_customizado":"valor"', $row[21]);
    }

    private function createUser(string $email): User
    {
        $user = new User();
        $user->setId(Uuid::v4());
        $user->setFirstname('Usuario');
        $user->setLastname('Teste');
        $user->setEmail($email);
        $user->setPassword('password');

        return $user;
    }

    private function createAgent(string $name, User $user, array $extraFields = []): Agent
    {
        $agent = new Agent();
        $agent->setId(Uuid::v4());
        $agent->setName($name);
        $agent->setShortBio('Short bio');
        $agent->setLongBio('Long bio');
        $agent->setCulture(false);
        $agent->setMain(false);
        $agent->setUser($user);
        $agent->setExtraFields($extraFields);
        $user->addAgent($agent);

        return $agent;
    }
}
