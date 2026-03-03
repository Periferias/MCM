<?php

declare(strict_types=1);

namespace App\Tests\Functional\Regmel\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class CleanOldExportsCommandTest extends KernelTestCase
{
    private string $exportsDir;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        self::bootKernel();

        $application = new Application(self::$kernel);
        $command = $application->find('app:regmel:clean-old-exports');
        $this->commandTester = new CommandTester($command);

        $projectDir = self::getContainer()->getParameter('kernel.project_dir');
        $this->exportsDir = sprintf('%s/storage/regmel/exports', $projectDir);

        // Cria diretório se não existir
        if (!is_dir($this->exportsDir)) {
            mkdir($this->exportsDir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        // Limpa todos os arquivos de teste
        if (is_dir($this->exportsDir)) {
            foreach (glob($this->exportsDir . '/*.zip') as $file) {
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

    public function testCommandDeletesOldFilesNotDownloaded(): void
    {
        // Arrange - cria arquivo com 3 horas de idade (não baixado)
        $oldFile = $this->exportsDir . '/anuencias_old_2026-03-03_12-00-00.zip';
        file_put_contents($oldFile, 'old content');
        touch($oldFile, time() - (3 * 3600)); // 3 horas atrás

        // Act
        $this->commandTester->execute([]);

        // Assert
        $this->assertFileDoesNotExist($oldFile);
        $this->assertStringContainsString('Removido', $this->commandTester->getDisplay());
        $this->assertStringContainsString('criado há', $this->commandTester->getDisplay());
        $this->assertStringContainsString('não baixado', $this->commandTester->getDisplay());
    }

    public function testCommandKeepsRecentFilesNotDownloaded(): void
    {
        // Arrange - cria arquivo com 1 hora de idade (não baixado)
        $recentFile = $this->exportsDir . '/anuencias_recent_2026-03-03_16-00-00.zip';
        file_put_contents($recentFile, 'recent content');
        touch($recentFile, time() - 3600); // 1 hora atrás

        // Act
        $this->commandTester->execute([]);

        // Assert
        $this->assertFileExists($recentFile);
    }

    public function testCommandDeletesDownloadedFilesAfter30Minutes(): void
    {
        // Arrange - cria arquivo baixado há 35 minutos
        $downloadedFile = $this->exportsDir . '/anuencias_downloaded_2026-03-03_15-00-00.zip';
        $downloadedMarker = $downloadedFile . '.downloaded';
        
        file_put_contents($downloadedFile, 'downloaded content');
        file_put_contents($downloadedMarker, (string) (time() - (35 * 60))); // 35 minutos atrás

        // Act
        $this->commandTester->execute([]);

        // Assert
        $this->assertFileDoesNotExist($downloadedFile);
        $this->assertFileDoesNotExist($downloadedMarker);
        $this->assertStringContainsString('Removido', $this->commandTester->getDisplay());
        $this->assertStringContainsString('baixado há', $this->commandTester->getDisplay());
    }

    public function testCommandKeepsDownloadedFilesWithin30Minutes(): void
    {
        // Arrange - cria arquivo baixado há 20 minutos
        $downloadedFile = $this->exportsDir . '/anuencias_recent_download_2026-03-03_16-40-00.zip';
        $downloadedMarker = $downloadedFile . '.downloaded';
        
        file_put_contents($downloadedFile, 'recent download content');
        file_put_contents($downloadedMarker, (string) (time() - (20 * 60))); // 20 minutos atrás

        // Act
        $this->commandTester->execute([]);

        // Assert
        $this->assertFileExists($downloadedFile);
        $this->assertFileExists($downloadedMarker);
    }

    public function testCommandHandlesMixedScenarios(): void
    {
        // Arrange
        // 1. Arquivo antigo não baixado (deve ser deletado)
        $oldNotDownloaded = $this->exportsDir . '/old_not_downloaded.zip';
        file_put_contents($oldNotDownloaded, 'content');
        touch($oldNotDownloaded, time() - (3 * 3600));

        // 2. Arquivo recente não baixado (deve ser mantido)
        $recentNotDownloaded = $this->exportsDir . '/recent_not_downloaded.zip';
        file_put_contents($recentNotDownloaded, 'content');
        touch($recentNotDownloaded, time() - 3600);

        // 3. Arquivo baixado há 35 min (deve ser deletado)
        $oldDownloaded = $this->exportsDir . '/old_downloaded.zip';
        file_put_contents($oldDownloaded, 'content');
        file_put_contents($oldDownloaded . '.downloaded', (string) (time() - (35 * 60)));

        // 4. Arquivo baixado há 20 min (deve ser mantido)
        $recentDownloaded = $this->exportsDir . '/recent_downloaded.zip';
        file_put_contents($recentDownloaded, 'content');
        file_put_contents($recentDownloaded . '.downloaded', (string) (time() - (20 * 60)));

        // Act
        $this->commandTester->execute([]);

        // Assert
        $this->assertFileDoesNotExist($oldNotDownloaded);
        $this->assertFileExists($recentNotDownloaded);
        $this->assertFileDoesNotExist($oldDownloaded);
        $this->assertFileExists($recentDownloaded);

        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Removidos 2 arquivo(s)', $output);
    }

    public function testCommandShowsSuccessMessageWhenNoFilesToDelete(): void
    {
        // Act
        $this->commandTester->execute([]);

        // Assert
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Removidos 0 arquivo(s)', $output);
        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testCommandHandlesNonExistentDirectory(): void
    {
        // Arrange - remove diretório
        if (is_dir($this->exportsDir)) {
            rmdir($this->exportsDir);
        }

        // Act
        $this->commandTester->execute([]);

        // Assert
        $this->assertStringContainsString('não existe', $this->commandTester->getDisplay());
        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testCommandDisplaysFileSizes(): void
    {
        // Arrange - cria arquivo antigo com conteúdo maior
        $oldFile = $this->exportsDir . '/large_file.zip';
        file_put_contents($oldFile, str_repeat('x', 1024 * 1024)); // 1MB
        touch($oldFile, time() - (3 * 3600));

        // Act
        $this->commandTester->execute([]);

        // Assert
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('MB', $output);
        $this->assertStringContainsString('liberados', $output);
    }
}
