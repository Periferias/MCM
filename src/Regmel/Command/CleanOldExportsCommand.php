<?php

declare(strict_types=1);

namespace App\Regmel\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

#[AsCommand(
    name: 'app:regmel:clean-old-exports',
    description: 'Remove arquivos de exportação (30min após download ou 2h)',
)]
class CleanOldExportsCommand extends Command
{
    public function __construct(
        private readonly ParameterBagInterface $parameterBag,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $exportsDir = sprintf(
            '%s/storage/regmel/exports',
            $this->parameterBag->get('kernel.project_dir')
        );
        
        if (!is_dir($exportsDir)) {
            $io->warning('Diretório de exports não existe');
            return Command::SUCCESS;
        }
        
        $now = time();
        $deletedCount = 0;
        $totalSize = 0;
        
        foreach (glob($exportsDir . '/*.zip') as $file) {
            if (!is_file($file)) {
                continue;
            }
            
            $downloadedMarkerPath = $file . '.downloaded';
            $shouldDelete = false;
            $reason = '';
            
            if (file_exists($downloadedMarkerPath)) {
                // Se foi baixado, apagar após 30 minutos do download
                $downloadTimestamp = (int) file_get_contents($downloadedMarkerPath);
                $timeSinceDownload = $now - $downloadTimestamp;
                
                if ($timeSinceDownload > (30 * 60)) { // 30 minutos
                    $shouldDelete = true;
                    $reason = sprintf('baixado há %d min', round($timeSinceDownload / 60));
                }
            } else {
                // Se não foi baixado, apagar após 2 horas da criação
                $timeSinceCreation = $now - filemtime($file);
                
                if ($timeSinceCreation > (2 * 3600)) { // 2 horas
                    $shouldDelete = true;
                    $reason = sprintf('criado há %d min (não baixado)', round($timeSinceCreation / 60));
                }
            }
            
            if ($shouldDelete) {
                $size = filesize($file);
                unlink($file);
                
                // Remove arquivo .downloaded se existir
                if (file_exists($downloadedMarkerPath)) {
                    unlink($downloadedMarkerPath);
                }
                
                $deletedCount++;
                $totalSize += $size;
                $io->writeln(sprintf(
                    "✓ Removido: %s (%s MB) - %s",
                    basename($file),
                    round($size/1024/1024, 2),
                    $reason
                ));
            }
        }
        
        $io->success(sprintf(
            'Removidos %d arquivo(s) antigo(s) - Total: %s MB liberados',
            $deletedCount,
            round($totalSize/1024/1024, 2)
        ));
        
        return Command::SUCCESS;
    }
}
