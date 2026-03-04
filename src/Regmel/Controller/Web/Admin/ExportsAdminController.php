<?php

declare(strict_types=1);

namespace App\Regmel\Controller\Web\Admin;

use App\Controller\Web\Admin\AbstractAdminController;
use App\Enum\UserRolesEnum;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class ExportsAdminController extends AbstractAdminController
{
    #[IsGranted(new Expression('
        is_granted("'.UserRolesEnum::ROLE_ADMIN->value.'") or
        is_granted("'.UserRolesEnum::ROLE_MANAGER->value.'") or
        is_granted("'.UserRolesEnum::ROLE_SUPPORT->value.'") or
        is_granted("'.UserRolesEnum::ROLE_MUNICIPALITY->value.'")
    '), statusCode: self::ACCESS_DENIED_RESPONSE_CODE)]
    #[Route('/painel/admin/exports/{filename}', name: 'admin_regmel_exports_download', methods: ['GET'])]
    public function downloadExport(string $filename): BinaryFileResponse
    {
        $exportsDir = sprintf(
            '%s/storage/regmel/exports',
            $this->getParameter('kernel.project_dir')
        );
        
        $filePath = sprintf('%s/%s', $exportsDir, $filename);
        
        // Validação de segurança contra path traversal
        $realExportsDir = realpath($exportsDir);
        $realFilePath = realpath($filePath);
        
        if (!$realFilePath || !str_starts_with($realFilePath, $realExportsDir)) {
            throw $this->createNotFoundException('Arquivo não encontrado');
        }
        
        if (!file_exists($filePath)) {
            throw $this->createNotFoundException('Arquivo não encontrado ou expirado');
        }
        
        // Marca timestamp de download para limpeza posterior
        $downloadedMarkerPath = $filePath . '.downloaded';
        if (!file_exists($downloadedMarkerPath)) {
            file_put_contents($downloadedMarkerPath, (string) time());
        }
        
        $response = new BinaryFileResponse($filePath);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $filename
        );
        
        // Não deleta após enviar, pois outras pessoas podem precisar
        return $response;
    }
}
