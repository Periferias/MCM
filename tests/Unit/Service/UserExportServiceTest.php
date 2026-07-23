<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Repository\Interface\UserRepositoryInterface;
use App\Service\UserExportService;
use DateTimeImmutable;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Settings;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

final class UserExportServiceTest extends TestCase
{
    private UserRepositoryInterface&MockObject $repository;
    private string $exportDirectory;
    private CacheInterface $originalSpreadsheetCache;

    protected function setUp(): void
    {
        $this->originalSpreadsheetCache = Settings::getCache();
        $this->repository = $this->createMock(UserRepositoryInterface::class);
        $this->exportDirectory = sprintf(
            '%s/pvr-user-export-%s',
            sys_get_temp_dir(),
            bin2hex(random_bytes(8))
        );
    }

    protected function tearDown(): void
    {
        Settings::setCache($this->originalSpreadsheetCache);

        if (!is_dir($this->exportDirectory)) {
            return;
        }

        $files = scandir($this->exportDirectory);
        if (false !== $files) {
            foreach ($files as $file) {
                if ('.' === $file || '..' === $file) {
                    continue;
                }

                $path = $this->exportDirectory.'/'.$file;
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }

        rmdir($this->exportDirectory);
    }

    public function testExportsCompleteRowAndHttpMetadata(): void
    {
        $this->repository->expects(self::once())
            ->method('findAllForExport')
            ->willReturn([[
                'user_id' => 'user-1',
                'user_name' => 'Ana Maria',
                'social_name' => 'Ana',
                'email' => 'ana@example.com',
                'status' => 'Active',
                'roles' => '["ROLE_ADMIN"]',
                'user_created_at' => '2025-01-02 03:04:05',
                'user_updated_at' => '2025-06-07 08:09:10',
                'agent_id' => 'agent-1',
                'agent_name' => 'Ana Agente',
                'agent_extra_fields' => '{"cpf":"012.345.678-90","cargo":"Coordenadora"}',
                'organization_id' => 'org-1',
                'organization_name' => 'Prefeitura de Exemplo',
                'organization_type' => 'Municipio',
                'organization_extra_fields' => '{"cnpj":"12.345.678/0001-90","city_name":"Exemplo","state":"SP"}',
                'owner_id' => 'agent-1',
            ]]);

        $response = $this->createService()->export();
        $file = $response->getFile()->getPathname();
        $sheet = $this->loadSheet($file);

        self::assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('Content-Type')
        );
        self::assertSame(
            'attachment; filename=usuarios_e_vinculos_2026-07-23_14-05-06.xlsx',
            $response->headers->get('Content-Disposition')
        );
        self::assertSame(UserExportService::HEADERS, $sheet->rangeToArray('A1:S1')[0]);
        self::assertSame([
            'user-1',
            'Ana Maria',
            'Ana',
            'ana@example.com',
            'Ativo',
            'Administrador Geral; Usuário Padrão',
            '02/01/2025 03:04:05',
            '07/06/2025 08:09:10',
            'agent-1',
            'Ana Agente',
            '012.345.678-90',
            'Coordenadora',
            'org-1',
            'Prefeitura de Exemplo',
            'Município',
            '12.345.678/0001-90',
            'Exemplo',
            'SP',
            'Sim',
        ], $sheet->rangeToArray('A2:S2')[0]);

        foreach ($sheet->getCellCollection()->getCoordinates() as $coordinate) {
            self::assertSame(DataType::TYPE_STRING, $sheet->getCell($coordinate)->getDataType());
        }

        ob_start();
        $response->sendContent();
        ob_end_clean();
        self::assertFileDoesNotExist($file);
    }

    public function testExportsOnlyHeadersWhenThereAreNoUsers(): void
    {
        $this->repository->method('findAllForExport')->willReturn([]);

        $response = $this->createService()->export();
        $sheet = $this->loadSheet($response->getFile()->getPathname());

        self::assertSame(UserExportService::HEADERS, $sheet->rangeToArray('A1:S1')[0]);
        self::assertSame(1, $sheet->getHighestDataRow());
    }

    public function testPreservesAgentWithoutOrganizationAndLeavesOrganizationColumnsEmpty(): void
    {
        $this->repository->method('findAllForExport')->willReturn([[
            'user_id' => 'user-2',
            'user_name' => 'Bruno',
            'social_name' => null,
            'email' => 'bruno@example.com',
            'status' => 'Blocked',
            'roles' => '[]',
            'user_created_at' => '2025-02-03 10:20:30',
            'user_updated_at' => null,
            'agent_id' => 'agent-2',
            'agent_name' => 'Bruno Agente',
            'agent_extra_fields' => '{"cpf":"987.654.321-00","cargo":"Analista"}',
            'organization_id' => null,
            'organization_name' => null,
            'organization_type' => null,
            'organization_extra_fields' => null,
            'owner_id' => null,
        ]]);

        $response = $this->createService()->export();
        $row = $this->loadSheet($response->getFile()->getPathname())->rangeToArray('A2:S2', '')[0];

        self::assertSame('Bloqueado', $row[4]);
        self::assertSame('Usuário Padrão', $row[5]);
        self::assertSame(['agent-2', 'Bruno Agente', '987.654.321-00', 'Analista'], array_slice($row, 8, 4));
        self::assertSame(array_fill(0, 7, ''), array_slice($row, 12, 7));
    }

    public function testWritesFormulaLikeOrganizationNameAsLiteralString(): void
    {
        $row = $this->minimalRow();
        $row['organization_id'] = 'org-danger';
        $row['organization_name'] = '=HYPERLINK("https://example.com","click")';
        $row['organization_type'] = 'Empresa';
        $row['organization_extra_fields'] = '{"cnpj":"00.000.000/0001-00","municipality":"Recife","uf":"PE"}';
        $row['owner_id'] = 'another-agent';
        $this->repository->method('findAllForExport')->willReturn([$row]);

        $response = $this->createService()->export();
        $cell = $this->loadSheet($response->getFile()->getPathname())->getCell('N2');

        self::assertSame('=HYPERLINK("https://example.com","click")', $cell->getValue());
        self::assertSame(DataType::TYPE_STRING, $cell->getDataType());
    }

    public function testConsumesRepositoryGeneratorOnlyOnce(): void
    {
        $iterations = 0;
        $rows = (function () use (&$iterations): iterable {
            ++$iterations;
            yield $this->minimalRow();
        })();
        $this->repository->expects(self::once())->method('findAllForExport')->willReturn($rows);

        $response = $this->createService()->export();
        $sheet = $this->loadSheet($response->getFile()->getPathname());

        self::assertSame(1, $iterations);
        self::assertSame(2, $sheet->getHighestDataRow());
    }

    public function testRemovesTemporaryFileWhenGeneratorThrowsAfterYieldingARow(): void
    {
        $rows = (function (): iterable {
            yield $this->minimalRow();

            throw new \RuntimeException('Falha durante a exportação.');
        })();
        $this->repository->method('findAllForExport')->willReturn($rows);

        try {
            $this->createService()->export();
            self::fail('A exceção do generator deveria ter sido propagada.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Falha durante a exportação.', $exception->getMessage());
        }

        self::assertSame(['.', '..'], scandir($this->exportDirectory));
    }

    public function testRestoresPhpSpreadsheetGlobalCacheAfterExport(): void
    {
        $originalCache = new Psr16Cache(new ArrayAdapter());
        Settings::setCache($originalCache);
        $this->repository->method('findAllForExport')->willReturn([]);

        $this->createService()->export();

        self::assertSame($originalCache, Settings::getCache());
    }

    public function testRequiresDedicatedCachePoolInConstructor(): void
    {
        $parameters = (new \ReflectionMethod(UserExportService::class, '__construct'))->getParameters();
        $cachePoolType = $parameters[3]->getType();

        self::assertInstanceOf(\ReflectionNamedType::class, $cachePoolType);
        self::assertSame(CacheItemPoolInterface::class, $cachePoolType->getName());
    }

    private function createService(): UserExportService
    {
        return new UserExportService(
            $this->repository,
            new MockClock(new DateTimeImmutable('2026-07-23 14:05:06 America/Sao_Paulo')),
            $this->exportDirectory,
            new ArrayAdapter()
        );
    }

    private function loadSheet(string $path): Worksheet
    {
        return IOFactory::load($path)->getActiveSheet();
    }

    /**
     * @return array<string, string|null>
     */
    private function minimalRow(): array
    {
        return [
            'user_id' => 'user-minimal',
            'user_name' => 'Usuário',
            'social_name' => null,
            'email' => 'user@example.com',
            'status' => 'AwaitingConfirmation',
            'roles' => '["ROLE_USER"]',
            'user_created_at' => '2025-03-04 05:06:07',
            'user_updated_at' => null,
            'agent_id' => null,
            'agent_name' => null,
            'agent_extra_fields' => null,
            'organization_id' => null,
            'organization_name' => null,
            'organization_type' => null,
            'organization_extra_fields' => null,
            'owner_id' => null,
        ];
    }
}
