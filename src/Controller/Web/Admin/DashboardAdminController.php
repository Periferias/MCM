<?php

declare(strict_types=1);

namespace App\Controller\Web\Admin;

use App\DocumentService\NotificationDocumentService;
use App\Enum\OrganizationTypeEnum;
use App\Enum\UserRolesEnum;
use App\Service\Interface\AgentServiceInterface;
use App\Service\Interface\EventServiceInterface;
use App\Service\Interface\InitiativeServiceInterface;
use App\Service\Interface\InscriptionOpportunityServiceInterface;
use App\Service\Interface\OpportunityServiceInterface;
use App\Service\Interface\OrganizationServiceInterface;
use App\Service\Interface\SpaceServiceInterface;
use Symfony\Component\HttpFoundation\Response;

class DashboardAdminController extends AbstractAdminController
{
    public function __construct(
        readonly private AgentServiceInterface $agentService,
        readonly private OpportunityServiceInterface $opportunityService,
        readonly private EventServiceInterface $eventService,
        readonly private SpaceServiceInterface $spaceService,
        readonly private InitiativeServiceInterface $initiativeService,
        readonly private InscriptionOpportunityServiceInterface $inscriptionService,
        readonly private OrganizationServiceInterface $organizationService,
        readonly private NotificationDocumentService $notificationService,
    ) {
    }

    public function index(): Response
    {
        $user = $this->getUser();

        if (
            true === in_array(UserRolesEnum::ROLE_COMPANY->value, $user->getRoles())
            && 1 === $user->getAgents()->count()
        ) {
            $companies = $this->organizationService->getCompaniesByAgents($user->getAgents());

            if (1 === count($companies)) {
                $company = reset($companies);

                return $this->redirectToRoute('admin_regmel_company_details', ['id' => $company->getId()]);
            }
        }

        if (
            true === in_array(UserRolesEnum::ROLE_MUNICIPALITY->value, $user->getRoles())
            && 1 === $user->getAgents()->count()
        ) {
            $cities = $this->organizationService->getMunicipalitiesByAgents($user->getAgents());

            if (1 === count($cities)) {
                $city = reset($cities);

                return $this->redirectToRoute('admin_regmel_municipality_details', ['id' => $city->getId()]);
            }
        }

        $recentRegistrations = $this->inscriptionService->findRecentByUser($user->getId());
        
        // Admin, Manager e Support veem todos os registros, outros usuários veem apenas os que criaram
        $isAdminOrManagerOrSupport = in_array(UserRolesEnum::ROLE_ADMIN->value, $user->getRoles()) 
            || in_array(UserRolesEnum::ROLE_MANAGER->value, $user->getRoles())
            || in_array(UserRolesEnum::ROLE_SUPPORT->value, $user->getRoles());
        
        $createdBy = $isAdminOrManagerOrSupport ? null : $this->agentService->getAgentsFromLoggedUser()[0];

        $totalAgents = $this->agentService->count($user);
        $totalUsers = $this->agentService->count();
        $totalOpportunities = $this->opportunityService->count($createdBy);
        $totalEvents = $this->eventService->count($createdBy);
        $totalSpaces = $this->spaceService->count($createdBy);
        $totalOrganizations = $this->organizationService->count();
        $totalInitiatives = $this->initiativeService->count($createdBy);
        $totalCities = count($this->organizationService->findBy([
            'type' => OrganizationTypeEnum::MUNICIPIO->value,
        ]));
        $totalCompanies = count($this->organizationService->findBy([
            'type' => OrganizationTypeEnum::EMPRESA->value,
        ]));
        
        // Propostas são iniciativas com campos específicos (map_file, project_file, etc)
        $allInitiatives = $isAdminOrManagerOrSupport 
            ? $this->initiativeService->findBy([], PHP_INT_MAX)
            : $this->initiativeService->findBy(['createdBy' => $createdBy], PHP_INT_MAX);
        $totalProposals = count(array_filter($allInitiatives, function($initiative) {
            $extraFields = $initiative->getExtraFields();
            return isset($extraFields['map_file']) || isset($extraFields['project_file']);
        }));

        $totals = [
            'totalUsers' => $totalUsers,
            'totalAgents' => $totalAgents,
            'totalOpportunities' => $totalOpportunities,
            'totalEvents' => $totalEvents,
            'totalSpaces' => $totalSpaces,
            'totalInitiatives' => $totalInitiatives,
            'totalCities' => $totalCities,
            'totalCompanies' => $totalCompanies,
            'totalOrganizations' => $totalOrganizations,
            'totalProposals' => $totalProposals,
        ];

        // Busca notificações do usuário logado
        $notifications = $this->notificationService->findByTarget($user->getId()->toRfc4122(), 10);
        $unvisitedCount = $this->notificationService->countUnvisitedByTarget($user->getId()->toRfc4122());

        return $this->render('dashboard/index.html.twig', [
            'user' => $user,
            'recentRegistrations' => $recentRegistrations,
            'totals' => $totals,
            'notifications' => $notifications,
            'unvisitedNotificationsCount' => $unvisitedCount,
        ]);
    }
}
