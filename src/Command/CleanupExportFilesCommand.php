<?php

declare(strict_types=1);

namespace App\Command;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

#[AsCommand(
    name: 'app:cleanup-export-files',
    description: 'Remove arquivos ZIP de exportação antigos (padrão: mais de 2 horas)',
)]
class CleanupExportFilesCommand extends Command
{
    public function __construct(
        private readonly ParameterBagInterface $parameterBag,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'max-age',
                null,
                InputOption::VALUE_REQUIRED,
                'Idade máxima dos arquivos em horas (padrão: 2)',
                '2'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Simular exclusão sem deletar arquivos'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $maxAgeHours = (int) $input->getOption('max-age');
        $dryRun = $input->getOption('dry-run');

        $exportDir = $this->parameterBag->get('kernel.project_dir') . '/var/exports';
        
        if (!is_dir($exportDir)) {
            $io->warning("Diretório de exportação não existe: {$exportDir}");
            return Command::SUCCESS;
        }

        $maxAgeSeconds = $maxAgeHours * 3600;
        $now = time();
        $deletedCount = 0;
        $deletedSize = 0;
        $keptCount = 0;

        $io->title('Limpeza de Arquivos de Exportação');
        $io->text([
            "Diretório: {$exportDir}",
            "Idade máxima: {$maxAgeHours}h",
            "Modo: " . ($dryRun ? 'SIMULAÇÃO (dry-run)' : 'REAL'),
        ]);
        $io->newLine();

        $files = glob($exportDir . '/*.zip');
        
        if (empty($files)) {
            $io->success('Nenhum arquivo ZIP encontrado.');
            return Command::SUCCESS;
        }

        $io->section("Analisando {$files} arquivos...");

        foreach ($files as $file) {
            $fileAge = $now - filemtime($file);
            $fileSize = filesize($file);
            $fileName = basename($file);
            $ageHours = round($fileAge / 3600, 1);

            if ($fileAge > $maxAgeSeconds) {
                if ($dryRun) {
                    $io->text("🔍 [DRY-RUN] Deletaria: {$fileName} ({$ageHours}h, " . $this->formatBytes($fileSize) . ")");
                } else {
                    if (unlink($file)) {
                        $io->text("✅ Deletado: {$fileName} ({$ageHours}h, " . $this->formatBytes($fileSize) . ")");
                        $deletedCount++;
                        $deletedSize += $fileSize;
                        
                        $this->logger->info('Arquivo de exportação deletado', [
                            'file' => $fileName,
                            'age_hours' => $ageHours,
                            'size_bytes' => $fileSize,
                        ]);
                    } else {
                        $io->error("❌ Erro ao deletar: {$fileName}");
                        $this->logger->error('Falha ao deletar arquivo de exportação', [
                            'file' => $fileName,
                        ]);
                    }
                }
            } else {
                $keptCount++;
                if ($output->isVerbose()) {
                    $io->text("⏳ Mantido: {$fileName} ({$ageHours}h, " . $this->formatBytes($fileSize) . ")");
                }
            }
        }

        $io->newLine();
        $io->section('Resumo');
        $io->table(
            ['Métrica', 'Valor'],
            [
                ['Arquivos deletados', $dryRun ? "{$deletedCount} (simulação)" : $deletedCount],
                ['Espaço liberado', $this->formatBytes($deletedSize)],
                ['Arquivos mantidos', $keptCount],
                ['Total analisado', count($files)],
            ]
        );

        if ($dryRun) {
            $io->warning('Modo DRY-RUN ativo. Nenhum arquivo foi deletado. Execute sem --dry-run para deletar.');
        } else {
            $io->success('Limpeza concluída com sucesso!');
        }

        return Command::SUCCESS;
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
