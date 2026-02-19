<?php

declare(strict_types=1);

namespace App\Controller\Api;

use Doctrine\DBAL\Connection;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class HealthCheckApiController extends AbstractApiController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly DocumentManager $documentManager,
        private readonly ParameterBagInterface $parameterBag,
    ) {
    }

    /**
     * Healthcheck endpoint - verifica o status geral da aplicação
     * 
     * Retorna HTTP 200 se tudo está OK
     * Retorna HTTP 503 se algum serviço crítico está indisponível
     */
    public function check(): JsonResponse
    {
        $checks = [];
        $overallStatus = 'healthy';
        $httpStatus = Response::HTTP_OK;

        // 1. Check Application
        $checks['application'] = $this->checkApplication();

        // 2. Check PostgreSQL
        $checks['postgresql'] = $this->checkPostgreSQL();

        // 3. Check MongoDB
        $checks['mongodb'] = $this->checkMongoDB();

        // 4. Check Environment Variables
        $checks['environment'] = $this->checkEnvironmentVariables();

        // 5. Check Disk Space (opcional)
        $checks['disk'] = $this->checkDiskSpace();

        // Determina o status geral
        foreach ($checks as $check) {
            if ($check['status'] === 'unhealthy') {
                $overallStatus = 'unhealthy';
                $httpStatus = Response::HTTP_SERVICE_UNAVAILABLE;
                break;
            }
            if ($check['status'] === 'degraded' && $overallStatus !== 'unhealthy') {
                $overallStatus = 'degraded';
            }
        }

        return new JsonResponse([
            'status' => $overallStatus,
            'timestamp' => date('c'),
            'environment' => $this->parameterBag->get('kernel.environment'),
            'checks' => $checks,
        ], $httpStatus);
    }

    /**
     * Liveness probe - verifica se a aplicação está viva
     * Usado pelo Kubernetes para saber se deve reiniciar o pod
     */
    public function liveness(): JsonResponse
    {
        return new JsonResponse([
            'status' => 'alive',
            'timestamp' => date('c'),
        ]);
    }

    /**
     * Readiness probe - verifica se a aplicação está pronta para receber tráfego
     * Usado pelo Kubernetes para saber se deve enviar tráfego para o pod
     */
    public function readiness(): JsonResponse
    {
        $ready = true;
        $checks = [];

        // Check PostgreSQL
        $postgresCheck = $this->checkPostgreSQL();
        $checks['postgresql'] = $postgresCheck;
        if ($postgresCheck['status'] === 'unhealthy') {
            $ready = false;
        }

        // Check MongoDB
        $mongoCheck = $this->checkMongoDB();
        $checks['mongodb'] = $mongoCheck;
        if ($mongoCheck['status'] === 'unhealthy') {
            $ready = false;
        }

        $httpStatus = $ready ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE;

        return new JsonResponse([
            'status' => $ready ? 'ready' : 'not_ready',
            'timestamp' => date('c'),
            'checks' => $checks,
        ], $httpStatus);
    }

    private function checkApplication(): array
    {
        return [
            'status' => 'healthy',
            'name' => 'Periferia Viva Reformas',
            'environment' => $this->parameterBag->get('kernel.environment'),
            'debug' => $this->parameterBag->get('kernel.debug'),
            'php_version' => PHP_VERSION,
            'symfony_version' => \Symfony\Component\HttpKernel\Kernel::VERSION,
        ];
    }

    private function checkPostgreSQL(): array
    {
        try {
            $result = $this->connection->executeQuery('SELECT 1 as status')->fetchAssociative();
            
            if ($result && $result['status'] === 1) {
                // Get database info
                $versionResult = $this->connection->executeQuery('SELECT version()')->fetchAssociative();
                $dbSize = $this->connection->executeQuery(
                    'SELECT pg_database_size(current_database()) as size'
                )->fetchAssociative();
                
                return [
                    'status' => 'healthy',
                    'message' => 'PostgreSQL connection successful',
                    'database' => $this->connection->getDatabase(),
                    'version' => $this->extractPostgresVersion($versionResult['version'] ?? 'unknown'),
                    'size_bytes' => $dbSize['size'] ?? 0,
                    'size_mb' => round(($dbSize['size'] ?? 0) / 1024 / 1024, 2),
                ];
            }

            return [
                'status' => 'unhealthy',
                'message' => 'PostgreSQL query returned unexpected result',
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'unhealthy',
                'message' => 'PostgreSQL connection failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    private function checkMongoDB(): array
    {
        try {
            // Tenta executar um comando ping
            $client = $this->documentManager->getClient();
            $database = $client->selectDatabase($this->documentManager->getConfiguration()->getDefaultDB());
            
            $command = ['ping' => 1];
            $result = $database->command($command);

            $resultArray = $result->toArray();
            
            if (isset($resultArray[0]['ok']) && $resultArray[0]['ok'] == 1) {
                // Get database stats
                $stats = $database->command(['dbStats' => 1])->toArray();
                
                $dbStats = $stats[0] ?? [];
                
                return [
                    'status' => 'healthy',
                    'message' => 'MongoDB connection successful',
                    'database' => $this->documentManager->getConfiguration()->getDefaultDB(),
                    'collections' => $dbStats['collections'] ?? 0,
                    'size_bytes' => $dbStats['dataSize'] ?? 0,
                    'size_mb' => round(($dbStats['dataSize'] ?? 0) / 1024 / 1024, 2),
                ];
            }

            return [
                'status' => 'unhealthy',
                'message' => 'MongoDB ping returned unexpected result',
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'unhealthy',
                'message' => 'MongoDB connection failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    private function checkEnvironmentVariables(): array
    {
        $requiredVars = [
            'APP_ENV',
            'APP_SECRET',
            'DATABASE_URL',
            'MONGODB_URI',
            'MONGODB_DB',
            'JWT_SECRET_KEY',
            'JWT_PUBLIC_KEY',
            'STORAGE_DIR',
        ];

        $missingVars = [];
        $presentVars = [];

        foreach ($requiredVars as $var) {
            $value = $_ENV[$var] ?? $_SERVER[$var] ?? getenv($var);
            if (empty($value)) {
                $missingVars[] = $var;
            } else {
                // Não expor valores sensíveis, apenas confirmar presença
                $presentVars[$var] = $this->isSensitiveVar($var) ? '***HIDDEN***' : 'present';
            }
        }

        $status = empty($missingVars) ? 'healthy' : 'unhealthy';

        return [
            'status' => $status,
            'message' => $status === 'healthy' 
                ? 'All required environment variables are set'
                : 'Missing required environment variables',
            'present' => $presentVars,
            'missing' => $missingVars,
        ];
    }

    private function checkDiskSpace(): array
    {
        try {
            $projectDir = $this->parameterBag->get('kernel.project_dir');
            $varDir = $projectDir . '/var';
            $storageDir = $projectDir . '/storage';

            $totalSpace = disk_total_space($projectDir);
            $freeSpace = disk_free_space($projectDir);
            $usedSpace = $totalSpace - $freeSpace;
            $usagePercent = ($usedSpace / $totalSpace) * 100;

            // Considera degraded se uso > 80%, unhealthy se > 90%
            $status = 'healthy';
            if ($usagePercent >= 90) {
                $status = 'unhealthy';
            } elseif ($usagePercent >= 80) {
                $status = 'degraded';
            }

            return [
                'status' => $status,
                'message' => sprintf('Disk usage: %.2f%%', $usagePercent),
                'total_gb' => round($totalSpace / 1024 / 1024 / 1024, 2),
                'used_gb' => round($usedSpace / 1024 / 1024 / 1024, 2),
                'free_gb' => round($freeSpace / 1024 / 1024 / 1024, 2),
                'usage_percent' => round($usagePercent, 2),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'degraded',
                'message' => 'Could not check disk space',
                'error' => $e->getMessage(),
            ];
        }
    }

    private function isSensitiveVar(string $var): bool
    {
        $sensitivePatterns = [
            'SECRET',
            'PASSWORD',
            'PASS',
            'KEY',
            'TOKEN',
            'CREDENTIAL',
            'DATABASE_URL',
            'MONGODB_URI',
            'DSN',
        ];

        foreach ($sensitivePatterns as $pattern) {
            if (stripos($var, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    private function extractPostgresVersion(string $versionString): string
    {
        // Extract version number from "PostgreSQL 16.1 on ..." format
        if (preg_match('/PostgreSQL\s+([0-9.]+)/', $versionString, $matches)) {
            return $matches[1];
        }
        
        return 'unknown';
    }
}
