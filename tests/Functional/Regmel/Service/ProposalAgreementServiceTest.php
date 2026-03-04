<?php

declare(strict_types=1);

namespace App\Tests\Functional\Regmel\Service;

use App\Regmel\Service\ProposalAgreementService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class ProposalAgreementServiceTest extends KernelTestCase
{
    private ProposalAgreementService $agreementService;
    private string $exportsDir;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->agreementService = self::getContainer()->get(ProposalAgreementService::class);
        $projectDir = self::getContainer()->getParameter('kernel.project_dir');
        $this->exportsDir = sprintf('%s/storage/regmel/exports', $projectDir);
    }

    protected function tearDown(): void
    {
        // Limpa arquivos de teste criados
        if (is_dir($this->exportsDir)) {
            foreach (glob($this->exportsDir . '/anuencias_*.zip') as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
                $downloadedMarker = $file . '.downloaded';
                if (file_exists($downloadedMarker)) {
                    unlink($downloadedMarker);
                }
            }
        }

        parent::tearDown();
    }

    public function testExportAllAgreementsAsyncCreatesZipFile(): void
    {
        // Arrange
        $userId = 'test-user-id-12345678';

        // Act
        $result = $this->agreementService->exportAllAgreementsAsync($userId);

        // Assert
        $this->assertIsArray($result);
        $this->assertArrayHasKey('path', $result);
        $this->assertArrayHasKey('filename', $result);
        $this->assertArrayHasKey('fileCount', $result);
        
        $this->assertFileExists($result['path']);
        $this->assertStringContainsString('anuencias_', $result['filename']);
        $this->assertStringContainsString(substr($userId, 0, 8), $result['filename']);
        $this->assertStringEndsWith('.zip', $result['filename']);
        
        $this->assertIsInt($result['fileCount']);
        $this->assertGreaterThanOrEqual(0, $result['fileCount']);
    }

    public function testExportAllAgreementsAsyncCreatesExportsDirectory(): void
    {
        // Arrange
        $userId = 'test-user-id-87654321';
        
        // Remove diretório se existir
        if (is_dir($this->exportsDir)) {
            foreach (glob($this->exportsDir . '/*') as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($this->exportsDir);
        }

        // Act
        $result = $this->agreementService->exportAllAgreementsAsync($userId);

        // Assert
        $this->assertDirectoryExists($this->exportsDir);
        $this->assertFileExists($result['path']);
    }

    public function testExportAllAgreementsAsyncFileNameFormat(): void
    {
        // Arrange
        $userId = 'abcd1234-5678-90ab-cdef-123456789012';

        // Act
        $result = $this->agreementService->exportAllAgreementsAsync($userId);

        // Assert
        $expectedPrefix = 'anuencias_' . substr($userId, 0, 8);
        $this->assertStringStartsWith($expectedPrefix, $result['filename']);
        
        // Verifica formato de timestamp YYYY-MM-DD_HH-MM-SS
        $this->assertMatchesRegularExpression(
            '/anuencias_[a-f0-9]{8}_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.zip/',
            $result['filename']
        );
    }

    public function testCountAgreements(): void
    {
        // Act
        $count = $this->agreementService->countAgreements();

        // Assert
        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function testCountAgreementsAwaitingApproval(): void
    {
        // Act
        $count = $this->agreementService->countAgreementsAwaitingApproval();

        // Assert
        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(0, $count);
    }
}
