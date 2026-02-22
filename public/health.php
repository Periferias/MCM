<?php

/**
 * Standalone Health Check
 * 
 * Este healthcheck é INDEPENDENTE do Symfony e funciona mesmo se a aplicação estiver quebrada.
 * Ideal para monitoramento externo (Kubernetes, load balancers, etc).
 * 
 * Endpoints:
 * - /health.php - Status completo
 * - /health.php?type=liveness - Apenas verifica se o script PHP está executando
 * - /health.php?type=readiness - Verifica se databases estão acessíveis
 */

declare(strict_types=1);

// Desabilita output buffering para resposta imediata
if (ob_get_level()) {
    ob_end_clean();
}

header('Content-Type: application/json');

// Carrega variáveis de ambiente
function health_loadEnv(string $projectDir): void
{
    $envFile = $projectDir . '/.env';
    if (!file_exists($envFile)) {
        return;
    }

    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        
        // Ignora comentários e linhas vazias
        if (empty($line) || str_starts_with($line, '#')) {
            continue;
        }

        // Parse KEY=VALUE
        if (strpos($line, '=') !== false) {
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            
            // Remove aspas se existirem
            $value = trim($value, '"\'');
            
            // Só define se não existir em $_ENV ou $_SERVER
            if (!isset($_ENV[$key]) && !isset($_SERVER[$key])) {
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
                putenv("$key=$value");
            }
        }
    }
}

function health_getEnv(string $key, string $default = ''): string
{
    return $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?: $default;
}

function health_checkPostgreSQL(): array
{
    try {
        $databaseUrl = health_getEnv('DATABASE_URL');
        if (empty($databaseUrl)) {
            return [
                'status' => 'unhealthy',
                'message' => 'DATABASE_URL not configured',
            ];
        }

        // Parse DATABASE_URL
        // Format: postgresql://user:pass@host:port/dbname?serverVersion=16&charset=utf8
        $parsed = parse_url($databaseUrl);
        
        $host = $parsed['host'] ?? 'localhost';
        $port = $parsed['port'] ?? 5432;
        $user = $parsed['user'] ?? '';
        $pass = $parsed['pass'] ?? '';
        $dbname = trim($parsed['path'] ?? '', '/');

        $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
        
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_TIMEOUT => 3,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $stmt = $pdo->query('SELECT 1 as status');
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result && $result['status'] == 1) {
            // Get version
            $versionStmt = $pdo->query('SELECT version()');
            $versionResult = $versionStmt->fetch(PDO::FETCH_ASSOC);
            $version = 'unknown';
            if (preg_match('/PostgreSQL\s+([0-9.]+)/', $versionResult['version'] ?? '', $matches)) {
                $version = $matches[1];
            }

            // Get database size
            $sizeStmt = $pdo->query('SELECT pg_database_size(current_database()) as size');
            $sizeResult = $sizeStmt->fetch(PDO::FETCH_ASSOC);
            $sizeBytes = $sizeResult['size'] ?? 0;

            return [
                'status' => 'healthy',
                'message' => 'PostgreSQL connection successful',
                'database' => $dbname,
                'version' => $version,
                'size_bytes' => (int)$sizeBytes,
                'size_mb' => round($sizeBytes / 1024 / 1024, 2),
            ];
        }

        return [
            'status' => 'unhealthy',
            'message' => 'PostgreSQL query returned unexpected result',
        ];
    } catch (Throwable $e) {
        return [
            'status' => 'unhealthy',
            'message' => 'PostgreSQL connection failed',
            'error' => $e->getMessage(),
        ];
    }
}

function health_checkMongoDB(): array
{
    try {
        $mongoUri = health_getEnv('MONGODB_URI');
        $mongoDb = health_getEnv('MONGODB_DB');

        if (empty($mongoUri) || empty($mongoDb)) {
            return [
                'status' => 'unhealthy',
                'message' => 'MongoDB configuration missing',
            ];
        }

        if (!class_exists('MongoDB\Driver\Manager')) {
            return [
                'status' => 'unhealthy',
                'message' => 'MongoDB extension not installed',
            ];
        }

        $manager = new MongoDB\Driver\Manager($mongoUri, [
            'connectTimeoutMS' => 3000,
            'serverSelectionTimeoutMS' => 3000,
        ]);

        // Execute ping command
        $command = new MongoDB\Driver\Command(['ping' => 1]);
        $cursor = $manager->executeCommand('admin', $command);
        $result = current($cursor->toArray());

        if (isset($result->ok) && $result->ok == 1) {
            // Get database stats
            $statsCommand = new MongoDB\Driver\Command(['dbStats' => 1]);
            $statsCursor = $manager->executeCommand($mongoDb, $statsCommand);
            $stats = current($statsCursor->toArray());

            return [
                'status' => 'healthy',
                'message' => 'MongoDB connection successful',
                'database' => $mongoDb,
                'collections' => $stats->collections ?? 0,
                'size_bytes' => (int)($stats->dataSize ?? 0),
                'size_mb' => round(($stats->dataSize ?? 0) / 1024 / 1024, 2),
            ];
        }

        return [
            'status' => 'unhealthy',
            'message' => 'MongoDB ping returned unexpected result',
        ];
    } catch (Throwable $e) {
        return [
            'status' => 'unhealthy',
            'message' => 'MongoDB connection failed',
            'error' => $e->getMessage(),
        ];
    }
}

function health_checkEnvironment(): array
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
        $value = health_getEnv($var);
        if (empty($value)) {
            $missingVars[] = $var;
        } else {
            $presentVars[$var] = health_isSensitiveVar($var) ? '***HIDDEN***' : 'present';
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

function health_checkDiskSpace(string $projectDir): array
{
    try {
        $totalSpace = disk_total_space($projectDir);
        $freeSpace = disk_free_space($projectDir);
        
        if ($totalSpace === false || $freeSpace === false) {
            return [
                'status' => 'degraded',
                'message' => 'Could not check disk space',
            ];
        }

        $usedSpace = $totalSpace - $freeSpace;
        $usagePercent = ($usedSpace / $totalSpace) * 100;

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
    } catch (Throwable $e) {
        return [
            'status' => 'degraded',
            'message' => 'Could not check disk space',
            'error' => $e->getMessage(),
        ];
    }
}

function health_isSensitiveVar(string $var): bool
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

// Main execution
try {
    $projectDir = dirname(__DIR__);
    health_loadEnv($projectDir);

    $type = $_GET['type'] ?? 'full';

    // Liveness probe - apenas verifica se PHP está executando
    if ($type === 'liveness') {
        http_response_code(200);
        echo json_encode([
            'status' => 'alive',
            'timestamp' => date('c'),
        ], JSON_PRETTY_PRINT);
        exit;
    }

    // Readiness probe - verifica se databases estão acessíveis
    if ($type === 'readiness') {
        $checks = [
            'postgresql' => health_checkPostgreSQL(),
            'mongodb' => health_checkMongoDB(),
        ];

        $ready = true;
        foreach ($checks as $check) {
            if ($check['status'] === 'unhealthy') {
                $ready = false;
                break;
            }
        }

        $httpStatus = $ready ? 200 : 503;
        http_response_code($httpStatus);

        echo json_encode([
            'status' => $ready ? 'ready' : 'not_ready',
            'timestamp' => date('c'),
            'checks' => $checks,
        ], JSON_PRETTY_PRINT);
        exit;
    }

    // Full health check
    $checks = [
        'application' => [
            'status' => 'healthy',
            'name' => 'Periferia Viva Reformas',
            'environment' => health_getEnv('APP_ENV', 'unknown'),
            'php_version' => PHP_VERSION,
        ],
        'postgresql' => health_checkPostgreSQL(),
        'mongodb' => health_checkMongoDB(),
        'environment' => health_checkEnvironment(),
        'disk' => health_checkDiskSpace($projectDir),
    ];

    // Determina o status geral
    $overallStatus = 'healthy';
    $httpStatus = 200;

    foreach ($checks as $check) {
        if ($check['status'] === 'unhealthy') {
            $overallStatus = 'unhealthy';
            $httpStatus = 503;
            break;
        }
        if ($check['status'] === 'degraded' && $overallStatus !== 'unhealthy') {
            $overallStatus = 'degraded';
        }
    }

    http_response_code($httpStatus);

    echo json_encode([
        'status' => $overallStatus,
        'timestamp' => date('c'),
        'environment' => health_getEnv('APP_ENV', 'unknown'),
        'standalone' => true,
        'checks' => $checks,
    ], JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Health check failed',
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ], JSON_PRETTY_PRINT);
}
