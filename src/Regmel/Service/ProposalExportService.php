<?php

declare(strict_types=1);

namespace App\Regmel\Service;

use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use ZipArchive;

class ProposalExportService
{
    public function __construct(
        private readonly ParameterBagInterface $parameterBag,
    ) {
    }

    public function export(string $tipo, array $proposals, ?string $outputPath, OutputInterface $output): ?string
    {
        return match ($tipo) {
            'poligonais' => $this->exportPoligonais($proposals, $outputPath, $output),
            'anuencias'  => $this->exportAnuencias($proposals, $outputPath, $output),
            default      => null,
        };
    }

    private function exportPoligonais(array $proposals, ?string $outputPath, OutputInterface $output): ?string
    {
        $zipPath = $this->resolveOutputPath('poligonais', $outputPath);

        $zip = new ZipArchive();
        if (true !== $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
            throw new RuntimeException('Não foi possível criar arquivo ZIP em: '.$zipPath);
        }

        $added = 0;
        foreach ($proposals as $proposal) {
            $extraFields = $proposal->getExtraFields();
            $mapFile = $extraFields['map_file'] ?? null;

            if (!$mapFile) {
                continue;
            }

            $filePath = $this->resolvePoligonalPath($mapFile);
            if (!file_exists($filePath)) {
                $output->writeln(sprintf('[WARN] Arquivo não encontrado em disco: %s', $filePath));
                continue;
            }

            $zip->addFile($filePath, basename($filePath));
            ++$added;
        }

        $zip->close();

        if (0 === $added) {
            @unlink($zipPath);

            return null;
        }

        $output->writeln(sprintf('[INFO] %d arquivo(s) adicionado(s) ao ZIP.', $added));

        return $zipPath;
    }

    private function exportAnuencias(array $proposals, ?string $outputPath, OutputInterface $output): ?string
    {
        $zipPath = $this->resolveOutputPath('anuencias', $outputPath);

        $zip = new ZipArchive();
        if (true !== $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
            throw new RuntimeException('Não foi possível criar arquivo ZIP em: '.$zipPath);
        }

        $added = 0;
        foreach ($proposals as $proposal) {
            $extraFields = $proposal->getExtraFields();
            $agreementFile = $extraFields['agreement_file'] ?? null;

            if (!$agreementFile) {
                continue;
            }

            $filePath = $this->resolveAgreementPath($agreementFile);
            if (!file_exists($filePath)) {
                $output->writeln(sprintf('[WARN] Arquivo não encontrado em disco: %s', $filePath));
                continue;
            }

            $zipEntryName = $this->buildAgreementFileName($proposal, $extraFields, $agreementFile);
            $zip->addFile($filePath, $zipEntryName);
            ++$added;
        }

        $zip->close();

        if (0 === $added) {
            @unlink($zipPath);

            return null;
        }

        $output->writeln(sprintf('[INFO] %d arquivo(s) adicionado(s) ao ZIP.', $added));

        return $zipPath;
    }

    private function buildAgreementFileName(object $proposal, array $extraFields, string $currentFileName): string
    {
        $cityNameRaw = $extraFields['city_name'] ?? 'municipio';
        $state = $extraFields['state'] ?? 'uf';

        // city_name é salvo como "NomeCidade-UF" — separa só o nome da cidade
        $stateSuffix = '-'.$state;
        if (str_ends_with($cityNameRaw, $stateSuffix)) {
            $cityNameRaw = substr($cityNameRaw, 0, -strlen($stateSuffix));
        }

        $company = $proposal->getOrganizationFrom()?->getName() ?? 'empresa';
        $uuid8 = substr($proposal->getId()->toRfc4122(), 0, 8);

        // Extrai versão do nome atual do arquivo (ex: _v01. ou -v01-)
        $version = 1;
        if (preg_match('/[_-]v(\d+)[._-]/i', $currentFileName, $matches)) {
            $version = (int) $matches[1];
        }

        return sprintf(
            '%s-%s-%s-anuencia-v%02d-%s.pdf',
            $this->slugify($cityNameRaw),
            strtoupper($state),
            $this->slugify($company),
            $version,
            $uuid8
        );
    }

    private function resolveOutputPath(string $tipo, ?string $outputPath): string
    {
        if ($outputPath) {
            return $outputPath;
        }

        $dir = $this->parameterBag->get('kernel.project_dir').'/storage/regmel/exports';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return sprintf('%s/%s_%s.zip', $dir, $tipo, date('Y-m-d_H-i-s'));
    }

    private function resolvePoligonalPath(string $fileName): string
    {
        $projectDir = $this->parameterBag->get('kernel.project_dir');

        return "{$projectDir}/storage/regmel/company/documents/{$fileName}";
    }

    private function resolveAgreementPath(string $fileName): string
    {
        $projectDir = $this->parameterBag->get('kernel.project_dir');

        return "{$projectDir}/storage/regmel/proposals/agreements/{$fileName}";
    }

    private function slugify(string $text): string
    {
        $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);

        return trim($text, '-');
    }
}
