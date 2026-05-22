<?php

namespace App\Regmel\Command;

use App\Entity\Initiative;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:populate-representative-data',
    description: 'Populate representative data for all proposals for testing purposes',
)]
class PopulateRepresentativeDataCommand extends Command
{
    public function __construct(private EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $initiatives = $this->em->getRepository(Initiative::class)->findAll();
        $count = 0;

        foreach ($initiatives as $initiative) {
            $extraFields = $initiative->getExtraFields() ?? [];

            // Only populate if not already set
            if (empty($extraFields['representative_name'])) {
                $extraFields['representative_name'] = 'João Silva';
                $extraFields['representative_cpf'] = '123.456.789-00';
                $extraFields['representative_email'] = 'joao.silva@empresa.com';
                $extraFields['representative_phone'] = '(11) 98765-4321';

                $initiative->setExtraFields($extraFields);
                $this->em->persist($initiative);
                $count++;
            }
        }

        $this->em->flush();

        $io->success(sprintf('Successfully populated representative data for %d proposals', $count));

        return Command::SUCCESS;
    }
}
