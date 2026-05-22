<?php

declare(strict_types=1);

namespace App\Command\Regmel;

use App\Enum\StatusProposalEnum;
use App\Regmel\Service\ProposalExportService;
use App\Service\Interface\InitiativeServiceInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:regmel:export-zip',
    description: 'Gera ZIP de poligonais ou anuências das propostas',
)]
class ExportZipCommand extends Command
{
    public function __construct(
        private readonly InitiativeServiceInterface $initiativeService,
        private readonly ProposalExportService $exportService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('tipo', InputArgument::REQUIRED, 'Tipo do export: poligonais | anuencias')
            ->addOption('status', null, InputOption::VALUE_OPTIONAL, 'Filtrar por status da proposta (ex: "Selecionada")')
            ->addOption('output', null, InputOption::VALUE_OPTIONAL, 'Caminho absoluto do arquivo ZIP de saída');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $tipo         = $input->getArgument('tipo');
        $statusFilter = $input->getOption('status');
        $outputPath   = $input->getOption('output');

        if (!in_array($tipo, ['poligonais', 'anuencias'], true)) {
            $io->error("Tipo inválido: '{$tipo}'. Use: poligonais, anuencias");

            return Command::FAILURE;
        }

        if (null !== $statusFilter) {
            $validValues = array_column(StatusProposalEnum::cases(), 'value');
            if (!in_array($statusFilter, $validValues, true)) {
                $io->error('Status inválido. Valores aceitos:');
                $io->listing($validValues);

                return Command::FAILURE;
            }
        }

        $io->title(sprintf('Export ZIP — %s', $tipo));

        $proposals = $this->initiativeService->list(10000);

        if (null !== $statusFilter) {
            $proposals = array_values(array_filter(
                $proposals,
                static fn ($p) => ($p->getExtraFields()['status'] ?? null) === $statusFilter
            ));
            $io->note(sprintf('Filtro aplicado: status = "%s" → %d proposta(s)', $statusFilter, count($proposals)));
        } else {
            $io->note(sprintf('Sem filtro de status → %d proposta(s) total', count($proposals)));
        }

        if (0 === count($proposals)) {
            $io->warning('Nenhuma proposta encontrada com os critérios informados.');

            return Command::FAILURE;
        }

        $zipPath = $this->exportService->export($tipo, $proposals, $outputPath, $output);

        if (!$zipPath) {
            $io->warning('Nenhum arquivo encontrado para exportar. ZIP não gerado.');

            return Command::FAILURE;
        }

        $io->success("ZIP gerado em: {$zipPath}");

        return Command::SUCCESS;
    }
}
