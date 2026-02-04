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
    description: 'Remove arquivos de exportação com mais de 48 horas',
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
        
        $maxAge = 48 * 3600; // 48 horas
        $now = time();
        $deletedCount = 0;
        $totalSize = 0;
        
        foreach (glob($exportsDir . '/*.zip') as $file) {
            if (is_file($file) && ($now - filemtime($file)) > $maxAge) {
                $size = filesize($file);
                unlink($file);
                $deletedCount++;
                $totalSize += $size;
                $io->writeln("✓ Removido: " . basename($file) . " (" . round($size/1024/1024, 2) . " MB)");
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
