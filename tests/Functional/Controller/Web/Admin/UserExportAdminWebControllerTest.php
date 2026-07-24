<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Web\Admin;

use App\Entity\User;
use App\Enum\UserRolesEnum;
use App\Enum\UserStatusEnum;
use App\Tests\AbstractWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

final class UserExportAdminWebControllerTest extends AbstractWebTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->client->loginUser($this->createActiveUser(UserRolesEnum::ROLE_ADMIN), 'web');
    }

    public function testAdminCanExportUserLinksAsXlsx(): void
    {
        $this->client->request(
            Request::METHOD_GET,
            $this->router->generate('admin_user_export_links')
        );

        $response = $this->client->getResponse();

        self::assertInstanceOf(BinaryFileResponse::class, $response);
        self::assertResponseIsSuccessful();
        self::assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('Content-Type')
        );
        self::assertMatchesRegularExpression(
            '/^attachment; filename=usuarios_e_vinculos_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.xlsx$/',
            (string) $response->headers->get('Content-Disposition')
        );
        self::assertFileDoesNotExist($response->getFile()->getPathname());
    }

    public function testStandardUserReceivesSameDenialAsUserList(): void
    {
        $this->client->loginUser($this->createActiveUser(UserRolesEnum::ROLE_USER), 'web');

        $this->client->request(
            Request::METHOD_GET,
            $this->router->generate('admin_user_list')
        );
        $listStatusCode = $this->client->getResponse()->getStatusCode();

        $this->client->request(
            Request::METHOD_GET,
            $this->router->generate('admin_user_export_links')
        );

        $response = $this->client->getResponse();

        self::assertSame($listStatusCode, $response->getStatusCode());
        self::assertFalse($response->isSuccessful());
    }

    public function testManagerCanExportUserLinks(): void
    {
        $this->client->loginUser($this->createActiveUser(UserRolesEnum::ROLE_MANAGER), 'web');

        $this->client->request(
            Request::METHOD_GET,
            $this->router->generate('admin_user_export_links')
        );

        $response = $this->client->getResponse();

        self::assertInstanceOf(BinaryFileResponse::class, $response);
        self::assertResponseIsSuccessful();
    }

    private function createActiveUser(UserRolesEnum $role): User
    {
        $id = Uuid::v4();
        $user = new User();
        $user->setId($id);
        $user->setFirstname('Usuário');
        $user->setLastname('Exportação');
        $user->setEmail(sprintf('export-web-%s@example.test', $id->toRfc4122()));
        $user->setPassword('not-used');
        $user->setStatus(UserStatusEnum::ACTIVE->value);
        $user->setRoles([$role->value]);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
