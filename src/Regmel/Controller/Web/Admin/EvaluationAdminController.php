<?php

declare(strict_types=1);

namespace App\Regmel\Controller\Web\Admin;

use App\Controller\Web\Admin\AbstractAdminController;
use App\Regmel\Service\Interface\ProposalEvaluationServiceInterface;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use App\Enum\UserRolesEnum;

class EvaluationAdminController extends AbstractAdminController
{
    public function __construct(
        private readonly ProposalEvaluationServiceInterface $evaluationService,
    ) {
    }

    #[IsGranted(new Expression('
        is_granted("'.UserRolesEnum::ROLE_ADMIN->value.'")
    '), statusCode: self::ACCESS_DENIED_RESPONSE_CODE)]
    #[Route('/painel/admin/avaliacoes', name: 'admin_evaluation_list', methods: ['GET'])]
    public function list(): Response
    {
        $proposalsAwaiting = $this->evaluationService->getProposalsAwaitingEvaluation();

        return $this->render('_admin/regmel/admin/evaluation/list.html.twig', [
            'proposals' => $proposalsAwaiting,
        ]);
    }
}
