<?php

declare(strict_types=1);

namespace App\Controller\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class SystemInfoApiController extends AbstractApiController
{
    public function __construct(
        private readonly ParameterBagInterface $parameterBag,
    ) {
    }

    public function getInfo(): JsonResponse
    {
        $projectDir = $this->parameterBag->get('kernel.project_dir');
        $gitCommit = $this->getGitCommit($projectDir);
        $gitBranch = $this->getGitBranch($projectDir);
        $version = $this->getVersion();

        $info = [
            'app' => [
                'name' => 'Periferia Viva Reformas',
                'version' => $version,
                'environment' => $this->parameterBag->get('kernel.environment'),
                'debug' => $this->parameterBag->get('kernel.debug'),
            ],
            'git' => [
                'commit' => $gitCommit,
                'branch' => $gitBranch,
            ],
            'php' => [
                'version' => PHP_VERSION,
            ],
            'symfony' => [
                'version' => \Symfony\Component\HttpKernel\Kernel::VERSION,
            ],
            'timestamp' => date('c'),
        ];

        return new JsonResponse($info);
    }

    private function getGitCommit(string $projectDir): ?string
    {
        // Try to get from environment variable first (for Docker/Kubernetes)
        $envCommit = getenv('GIT_COMMIT');
        if ($envCommit !== false && !empty($envCommit)) {
            return $envCommit;
        }

        $headFile = $projectDir . '/.git/HEAD';
        
        if (!file_exists($headFile)) {
            return null;
        }

        $head = trim(file_get_contents($headFile));
        
        if (str_starts_with($head, 'ref:')) {
            $ref = substr($head, 5);
            $refFile = $projectDir . '/.git/' . trim($ref);
            
            if (file_exists($refFile)) {
                return trim(file_get_contents($refFile));
            }
        } else {
            return $head;
        }

        return null;
    }

    private function getGitBranch(string $projectDir): ?string
    {
        // Try to get from environment variable first (for Docker/Kubernetes)
        $envBranch = getenv('GIT_BRANCH');
        if ($envBranch !== false && !empty($envBranch)) {
            return $envBranch;
        }

        $headFile = $projectDir . '/.git/HEAD';
        
        if (!file_exists($headFile)) {
            return null;
        }

        $head = trim(file_get_contents($headFile));
        
        if (str_starts_with($head, 'ref: refs/heads/')) {
            return substr($head, 16);
        }

        return null;
    }

    private function getVersion(): string
    {
        $projectDir = $this->parameterBag->get('kernel.project_dir');
        
        // Tenta obter a versão da última tag do Git
        $gitTag = $this->getGitTag($projectDir);
        
        return $gitTag ?? 'dev';
    }

    private function getGitTag(string $projectDir): ?string
    {
        $packedRefsFile = $projectDir . '/.git/packed-refs';
        $refsTagsDir = $projectDir . '/.git/refs/tags/';
        
        $tags = [];
        
        // Lê tags do arquivo packed-refs
        if (file_exists($packedRefsFile)) {
            $packedRefs = file_get_contents($packedRefsFile);
            preg_match_all('/^([a-f0-9]{40}) refs\/tags\/(.+)$/m', $packedRefs, $matches);
            
            if (!empty($matches[2])) {
                $tags = array_merge($tags, $matches[2]);
            }
        }
        
        // Lê tags do diretório refs/tags/
        if (is_dir($refsTagsDir)) {
            $tagFiles = scandir($refsTagsDir);
            foreach ($tagFiles as $tagFile) {
                if ($tagFile !== '.' && $tagFile !== '..') {
                    $tags[] = $tagFile;
                }
            }
        }
        
        if (empty($tags)) {
            return null;
        }
        
        // Ordena as tags usando version_compare para pegar a mais recente
        usort($tags, function ($a, $b) {
            return version_compare($b, $a);
        });
        
        return $tags[0];
    }
}
