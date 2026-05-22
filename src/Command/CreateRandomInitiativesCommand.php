<?php

// php bin/console app:create-random-regmel-proposals --count=200

declare(strict_types=1);

namespace App\Command;

use App\Entity\Initiative;
use App\Entity\Organization;
use App\Entity\User;
use App\Enum\OrganizationTypeEnum;
use App\Enum\StatusProposalEnum;
use App\Repository\Interface\CityRepositoryInterface;
use App\Repository\Interface\OrganizationRepositoryInterface;
use App\Repository\Interface\UserRepositoryInterface;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Uid\Uuid;

#[AsCommand(
    name: 'app:create-random-regmel-proposals',
    description: 'Cria propostas REGMEL aleatórias para testes',
)]
class CreateRandomInitiativesCommand extends Command
{
    private const array INTERVENTION_AREA_NAMES = [
        'Vila Nova', 'Jardim das Flores', 'Morro do Sol', 'Parque das Nações',
        'Comunidade Esperança', 'Vila União', 'Bairro Progresso', 'Assentamento Boa Vista',
        'Núcleo Habitacional Palmeiras', 'Comunidade São José', 'Vila Santa Maria',
        'Parque Industrial', 'Conjunto Residencial Arvoredo', 'Vila dos Trabalhadores',
        'Comunidade Vitória', 'Favela da Paz', 'Morro Alto', 'Vila Esperança',
        'Assentamento Nova Era', 'Bairro Popular', 'Comunidade Horizonte',
        'Vila Verde', 'Parque São Pedro', 'Núcleo Boa Esperança', 'Vila União II',
        'Comunidade Renascer', 'Jardim Primavera', 'Vila Nova Vida', 'Parque dos Sonhos',
        'Assentamento Liberdade', 'Comunidade Arco-Íris', 'Vila Progresso II',
    ];

    private const array STREET_NAMES = [
        'Rua das Flores', 'Av. Principal', 'Rua da Paz', 'Travessa do Comércio',
        'Rua São João', 'Av. Brasil', 'Rua do Progresso', 'Beco da Alegria',
        'Rua Santa Maria', 'Av. das Palmeiras', 'Rua Esperança', 'Travessa Central',
    ];

    private const array AREA_CHARACTERISTICS = [
        'option_1', // Núcleo urbano regularizado
        'option_2', // Núcleo urbano informal Reurb-S
        'option_3', // Zona especial de interesse social
        'option_4', // Excepcionalidade
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly OrganizationRepositoryInterface $organizationRepository,
        private readonly CityRepositoryInterface $cityRepository,
        private readonly UserRepositoryInterface $userRepository,
        private readonly TokenStorageInterface $tokenStorage,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('count', 'c', InputOption::VALUE_OPTIONAL, 'Número de propostas a criar', 50)
            ->addOption('batch-size', 'b', InputOption::VALUE_OPTIONAL, 'Tamanho do lote para flush', 10);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $count = (int) $input->getOption('count');
        $batchSize = (int) $input->getOption('batch-size');

        $io->title("Criando {$count} propostas REGMEL aleatórias");

        // Autentica um usuário para o audit listener
        $user = $this->userRepository->findAll()[0] ?? null;
        if ($user instanceof User) {
            $this->authenticateUser($user);
        }

        // Busca empresas e municípios existentes
        $companies = $this->organizationRepository->findBy(['type' => OrganizationTypeEnum::EMPRESA->value]);
        $municipalities = $this->organizationRepository->findBy(['type' => OrganizationTypeEnum::MUNICIPIO->value]);
        $cities = $this->cityRepository->findAll();

        if (empty($companies)) {
            $io->error('Nenhuma empresa encontrada. Execute as fixtures primeiro.');
            return Command::FAILURE;
        }

        if (empty($cities)) {
            $io->error('Nenhuma cidade encontrada. Execute as fixtures primeiro.');
            return Command::FAILURE;
        }

        $io->info(sprintf('Encontradas %d empresas, %d municípios e %d cidades', count($companies), count($municipalities), count($cities)));

        $io->progressStart($count);

        for ($i = 0; $i < $count; ++$i) {
            // Recarrega entidades a cada lote para evitar entidades des-anexadas
            if ($i % $batchSize === 0 && $i > 0) {
                $companies = $this->organizationRepository->findBy(['type' => OrganizationTypeEnum::EMPRESA->value]);
                $municipalities = $this->organizationRepository->findBy(['type' => OrganizationTypeEnum::MUNICIPIO->value]);
                $cities = $this->cityRepository->findAll();
            }

            $initiative = $this->createRandomProposal($companies, $municipalities, $cities, $user);
            $this->entityManager->persist($initiative);

            if (($i + 1) % $batchSize === 0) {
                $this->entityManager->flush();
                $this->entityManager->clear();
                $io->progressAdvance($batchSize);
            }
        }

        $this->entityManager->flush();
        $this->entityManager->clear();
        $io->progressFinish();

        $io->success("{$count} propostas REGMEL criadas com sucesso!");

        return Command::SUCCESS;
    }

    private function authenticateUser(User $user): void
    {
        $token = new UsernamePasswordToken($user, 'web');
        $this->tokenStorage->setToken($token);
    }

    private function createRandomProposal(array $companies, array $municipalities, array $cities, User $user): Initiative
    {
        $initiative = new Initiative();
        $initiative->setId(Uuid::v4());

        // Nome da área de intervenção
        $areaName = self::INTERVENTION_AREA_NAMES[array_rand(self::INTERVENTION_AREA_NAMES)];
        $suffix = rand(1, 999);
        $initiative->setName("{$areaName} {$suffix}");

        // Empresa aleatória
        /** @var Organization $company */
        $company = $companies[array_rand($companies)];
        $initiative->setOrganizationFrom($company);

        // Pega o primeiro agente da empresa como criador
        $agent = $company->getAgents()->first();
        if ($agent) {
            $initiative->setCreatedBy($agent);
        } else {
            // Fallback: usa o agente do primeiro usuário
            $initiative->setCreatedBy($user->getAgents()->first());
        }

        // Cidade aleatória
        $city = $cities[array_rand($cities)];
        $state = $city->getState()->getAcronym();
        $cityName = $city->getName();
        $cityCode = $city->getCityCode() ?? '';
        $region = $city->getState()->getRegion()->value ?? 'Nordeste';

        // Tenta encontrar organização municipio correspondente (50% de chance)
        $municipality = null;
        if (rand(0, 1) === 1 && !empty($municipalities)) {
            foreach ($municipalities as $mun) {
                $munCityId = $mun->getExtraFields()['cityId'] ?? null;
                if ($munCityId && $munCityId === $city->getId()->toRfc4122()) {
                    $municipality = $mun;
                    break;
                }
            }
        }

        $initiative->setOrganizationTo($municipality);

        // Determina o status baseado no município
        $status = StatusProposalEnum::SEM_ADESAO->value;
        if ($municipality) {
            $termStatus = $municipality->getExtraFields()['term_status'] ?? null;
            $status = match ($termStatus) {
                'approved' => StatusProposalEnum::RECEBIDA->value,
                'rejected', 'awaiting' => StatusProposalEnum::ENVIADA->value,
                default => StatusProposalEnum::SEM_ADESAO->value,
            };

            // 30% de chance de ter status mais avançado se município tem termo aprovado
            if ($termStatus === 'approved' && rand(0, 9) < 3) {
                $advancedStatuses = [
                    StatusProposalEnum::ANUIDA->value,
                    StatusProposalEnum::NAO_ANUIDA->value,
                    StatusProposalEnum::SELECIONADA->value,
                    StatusProposalEnum::NAO_SELECIONADA->value,
                ];
                $status = $advancedStatuses[array_rand($advancedStatuses)];
            }
        }

        // Endereço aleatório
        $street = self::STREET_NAMES[array_rand(self::STREET_NAMES)];
        $number = rand(1, 999);
        $neighborhood = ['Centro', 'Periferia', 'Zona Norte', 'Zona Sul', 'Subúrbio'][rand(0, 4)];
        $address = "{$street}, {$number}, {$neighborhood}";

        // CEP aleatório (formato brasileiro)
        $zipcode = sprintf('%05d-%03d', rand(10000, 99999), rand(0, 999));

        // Área estimada (m²) e número de domicílios
        $areaSize = rand(5000, 50000);
        $quantityHouses = rand(10, 140); // Min 10, Max 140

        // Caracterização da área
        $areaCharacteristic = self::AREA_CHARACTERISTICS[array_rand(self::AREA_CHARACTERISTICS)];

        // Vinculação com SNPR (60% sim, 40% não)
        $snprAffiliation = rand(0, 9) < 6 ? 'yes' : 'no';
        $snprDetails = null;
        if ($snprAffiliation === 'yes') {
            $programs = [
                'Programa Habitacional Popular',
                'Regularização Fundiária Urbana',
                'Minha Casa Minha Vida',
                'Periferia Viva',
                'Urbanização de Assentamentos Precários',
            ];
            $snprDetails = $programs[array_rand($programs)];
        }

        // Simula nomes de arquivos
        $inscriptionNumber = substr($initiative->getId()->toRfc4122(), 0, 8);
        $mapFileName = "{$inscriptionNumber}_01_area-poligonal.pdf";
        $projectFileName = "{$inscriptionNumber}_02_poligonal-georref.kml";

        // Extra fields
        $extraFields = [
            'city_name' => "{$cityName}-{$state}",
            'city_code' => $cityCode,
            'state' => $state,
            'region' => $region,
            'address' => $address,
            'zipcode' => $zipcode,
            'area_characteristic' => $areaCharacteristic,
            'area_size' => $areaSize,
            'quantity_houses' => $quantityHouses,
            'status' => $status,
            'status_reason' => $status === StatusProposalEnum::NAO_ANUIDA->value 
                ? 'Área não atende aos critérios de elegibilidade estabelecidos.' 
                : '',
            'snpr_affiliation' => $snprAffiliation,
            'snpr_affiliation_details' => $snprDetails,
            'map_file' => $mapFileName,
            'project_file' => $projectFileName,
        ];

        $initiative->setExtraFields($extraFields);

        // Data de criação aleatória (últimos 90 dias)
        $createdDaysAgo = rand(1, 90);
        $createdAt = (new DateTimeImmutable())->modify("-{$createdDaysAgo} days");
        $initiative->setCreatedAt($createdAt);

        return $initiative;
    }
}
