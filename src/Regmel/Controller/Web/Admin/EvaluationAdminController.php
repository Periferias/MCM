<?php

declare(strict_types=1);

namespace App\Regmel\Controller\Web\Admin;

use App\Controller\Web\Admin\AbstractAdminController;
use App\Regmel\Service\Interface\ProposalEvaluationServiceInterface;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
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

        return $this->render('regmel/admin/evaluation/list.html.twig', [
            'proposals' => $proposalsAwaiting,
        ]);
    }

    #[IsGranted(new Expression('
        is_granted("'.UserRolesEnum::ROLE_ADMIN->value.'")
    '), statusCode: self::ACCESS_DENIED_RESPONSE_CODE)]
    #[Route('/painel/admin/avaliacoes/{id}/submit', name: 'admin_evaluation_submit', methods: ['POST'])]
    public function submit(Request $request, string $id): JsonResponse
    {
        try {
            $proposalId = Uuid::fromString($id);
            $data = json_decode($request->getContent(), true);

            $result = $data['result'] ?? null;
            $reason = $data['reason'] ?? null;
            $notes = $data['notes'] ?? null;
            $ranking = $data['ranking'] ?? null;

            if (!$result || !$reason) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Resultado e motivo são obrigatórios',
                ], JsonResponse::HTTP_BAD_REQUEST);
            }

            // Converter a string do resultado para o enum
            $resultEnum = \App\Regmel\Enum\EvaluationResultEnum::from($result);

            $this->evaluationService->evaluate(
                $proposalId,
                $resultEnum,
                $reason,
                $notes,
                (int) $ranking
            );

            return new JsonResponse([
                'success' => true,
                'message' => 'Proposta avaliada com sucesso',
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
            ], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}

