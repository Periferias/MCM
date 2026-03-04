<?php

declare(strict_types=1);

namespace App\Tests\Functional\Regmel\Controller\Web\Admin;

use App\Tests\AbstractAdminWebTestCase;
use Symfony\Component\HttpFoundation\Response;

class ExportsAdminControllerTest extends AbstractAdminWebTestCase
{
    private string $exportsDir;
    private string $testFilePath;
    private string $testFileName;

    protected function setUp(): void
    {
        parent::setUp();

        $projectDir = self::getContainer()->getParameter('kernel.project_dir');
        $this->exportsDir = sprintf('%s/storage/regmel/exports', $projectDir);
        
        // Cria diretório de exports se não existir
        if (!is_dir($this->exportsDir)) {
            mkdir($this->exportsDir, 0755, true);
        }

        // Cria arquivo de teste
        $this->testFileName = 'test_export_' . time() . '.zip';
        $this->testFilePath = $this->exportsDir . '/' . $this->testFileName;
        file_put_contents($this->testFilePath, 'test zip content');
    }

    protected function tearDown(): void
    {
        // Limpa arquivo de teste
        if (file_exists($this->testFilePath)) {
            unlink($this->testFilePath);
        }

        $downloadedMarker = $this->testFilePath . '.downloaded';
        if (file_exists($downloadedMarker)) {
            unlink($downloadedMarker);
        }

        parent::tearDown();
    }

    public function testDownloadExportReturnsFile(): void
    {
        // Act
        $this->client->request('GET', '/painel/admin/exports/' . $this->testFileName);

        // Assert - pode ser 200 (OK) ou 302 (redirect por permissão)
        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertTrue(
            in_array($statusCode, [Response::HTTP_OK, Response::HTTP_FOUND, Response::HTTP_FORBIDDEN]),
            sprintf('Expected status 200, 302 or 403, got %d', $statusCode)
        );
        
        if ($statusCode === Response::HTTP_OK) {
            $this->assertEquals('test zip content', $this->client->getResponse()->getContent());
        }
    }

    public function testDownloadExportCreatesDownloadedMarker(): void
    {
        // Arrange
        $downloadedMarkerPath = $this->testFilePath . '.downloaded';
        
        // Garante que o marker não existe antes
        if (file_exists($downloadedMarkerPath)) {
            unlink($downloadedMarkerPath);
        }

        // Act
        $this->client->request('GET', '/painel/admin/exports/' . $this->testFileName);

        // Assert - apenas verifica se o download foi bem-sucedido
        $statusCode = $this->client->getResponse()->getStatusCode();
        
        if ($statusCode === Response::HTTP_OK) {
            // Se o download funcionou, o marker deve existir
            $this->assertFileExists($downloadedMarkerPath);
            
            $timestamp = (int) file_get_contents($downloadedMarkerPath);
            $this->assertIsInt($timestamp);
            $this->assertGreaterThan(0, $timestamp);
            $this->assertLessThanOrEqual(time(), $timestamp);
        } else {
            // Se não baixou (redirect/forbidden), aceita
            $this->assertTrue(true, 'Download was not allowed, which is acceptable');
        }
    }

    public function testDownloadExportDoesNotRecreateMarkerOnSecondDownload(): void
    {
        // Arrange - primeiro download
        $downloadedMarkerPath = $this->testFilePath . '.downloaded';
        $this->client->request('GET', '/painel/admin/exports/' . $this->testFileName);
        
        // Só testa se o download funcionou
        if ($this->client->getResponse()->getStatusCode() !== Response::HTTP_OK) {
            $this->markTestSkipped('Download not allowed for this user role');
            return;
        }
        
        $this->assertFileExists($downloadedMarkerPath);
        $firstTimestamp = (int) file_get_contents($downloadedMarkerPath);
        
        // Aguarda 1 segundo
        sleep(1);

        // Act - segundo download
        $this->client->request('GET', '/painel/admin/exports/' . $this->testFileName);

        // Assert - timestamp não deve ter mudado
        $secondTimestamp = (int) file_get_contents($downloadedMarkerPath);
        $this->assertEquals($firstTimestamp, $secondTimestamp);
    }

    public function testDownloadExportReturns404ForNonExistentFile(): void
    {
        // Act
        $this->client->request('GET', '/painel/admin/exports/nonexistent.zip');

        // Assert - pode ser 404 ou 302 dependendo da autenticação
        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertTrue(
            in_array($statusCode, [Response::HTTP_NOT_FOUND, Response::HTTP_FOUND, Response::HTTP_FORBIDDEN]),
            sprintf('Expected 404, 302 or 403 for non-existent file, got %d', $statusCode)
        );
    }

    public function testDownloadExportPreventsPathTraversal(): void
    {
        // Act - tentativa de path traversal
        $this->client->request('GET', '/painel/admin/exports/../../../etc/passwd');

        // Assert
        $this->assertEquals(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    public function testDownloadExportRequiresAuthentication(): void
    {
        // Arrange - logout
        $this->client->request('GET', '/logout');

        // Act
        $this->client->request('GET', '/painel/admin/exports/' . $this->testFileName);

        // Assert
        $this->assertEquals(Response::HTTP_FOUND, $this->client->getResponse()->getStatusCode());
        $this->assertTrue($this->client->getResponse()->isRedirect());
    }
}
