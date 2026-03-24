<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Regmel\Enum\EvaluationResultEnum;
use App\Regmel\Service\Interface\ProposalEvaluationServiceInterface;
use App\Regmel\Service\ProposalOrderingService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

class EvaluationApiController extends AbstractApiController
{
    public function __construct(
        private readonly ProposalEvaluationServiceInterface $evaluationService,
        private readonly ProposalOrderingService $orderingService,
    ) {
    }

    #[IsGranted('ROLE_ADMIN')]
    public function getEvaluation(Uuid $id): JsonResponse
    {
        $evaluation = $this->evaluationService->getEvaluation($id);

        if (!$evaluation) {
            return $this->json([
                'success' => false,
                'message' => 'Esta proposta ainda não foi avaliada.',
            ], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'success' => true,
            'data' => $evaluation,
        ]);
    }

    #[IsGranted('ROLE_ADMIN')]
    public function evaluate(Uuid $id, Request $request): JsonResponse
    {
        try {
            $data = $request->toArray();

            // Validar campos obrigatórios
            if (!isset($data['result']) || !isset($data['reason'])) {
                return $this->json([
                    'success' => false,
                    'message' => 'Os campos "result" e "reason" são obrigatórios.',
                ], Response::HTTP_BAD_REQUEST);
            }

            // Validar enum
            $result = null;
            foreach (EvaluationResultEnum::cases() as $case) {
                if ($case->value === $data['result']) {
                    $result = $case;
                    break;
                }
            }

            if (!$result) {
                return $this->json([
                    'success' => false,
                    'message' => 'Resultado inválido. Use: "Selecionada", "Não Selecionada" ou "Classificada".',
                ], Response::HTTP_BAD_REQUEST);
            }

            $notes = $data['notes'] ?? null;
            $ranking = isset($data['ranking']) ? (int) $data['ranking'] : null;

            $this->evaluationService->evaluate(
                $id,
                $result,
                $data['reason'],
                $notes,
                $ranking,
            );

            return $this->json([
                'success' => true,
                'message' => 'Proposta avaliada com sucesso.',
            ], Response::HTTP_OK);
        } catch (\InvalidArgumentException $e) {
            return $this->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'Erro ao avaliar proposta: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[IsGranted('ROLE_ADMIN')]
    public function uploadEvaluationDocument(Uuid $id, Request $request): JsonResponse
    {
        try {
            $file = $request->files->get('document');
            if (!$file) {
                return $this->json([
                    'success' => false,
                    'message' => 'Arquivo não fornecido.',
                ], Response::HTTP_BAD_REQUEST);
            }

            $result = $request->request->get('result');
            $reason = $request->request->get('reason');
            $notes = $request->request->get('notes');
            $rankingValue = $request->request->get('ranking');
            $ranking = null !== $rankingValue ? (int) $rankingValue : null;

            // Validar resultado
            $resultEnum = null;
            foreach (EvaluationResultEnum::cases() as $case) {
                if ($case->value === $result) {
                    $resultEnum = $case;
                    break;
                }
            }

            if (!$resultEnum) {
                return $this->json([
                    'success' => false,
                    'message' => 'Resultado inválido.',
                ], Response::HTTP_BAD_REQUEST);
            }

            $this->evaluationService->evaluate(
                $id,
                $resultEnum,
                $reason,
                $notes,
                $ranking,
                $file,
            );

            return $this->json([
                'success' => true,
                'message' => 'Documento de avaliação carregado com sucesso.',
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'Erro ao fazer upload: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[IsGranted('ROLE_ADMIN')]
    public function getProposalsAwaitingEvaluation(Request $request): JsonResponse
    {
        try {
            $region = $request->query->get('region');
            $state = $request->query->get('state');

            $proposals = $this->evaluationService->getProposalsAwaitingEvaluation($region, $state);

            return $this->json([
                'success' => true,
                'data' => $proposals,
                'total' => count($proposals),
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'Erro ao obter propostas: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[IsGranted('ROLE_ADMIN')]
    public function getOrderedProposals(Request $request): JsonResponse
    {
        try {
            $status = $request->query->get('status');
            $region = $request->query->get('region');
            $state = $request->query->get('state');

            $proposals = $this->orderingService->getProposalsOrdered($status, $region, $state);

            return $this->json([
                'success' => true,
                'data' => $proposals,
                'total' => count($proposals),
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'Erro ao obter propostas ordenadas: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[IsGranted('ROLE_ADMIN')]
    public function reorderProposals(Request $request): JsonResponse
    {
        try {
            $data = $request->toArray();

            if (!isset($data['reordering']) || !is_array($data['reordering'])) {
                return $this->json([
                    'success' => false,
                    'message' => 'Campo "reordering" deve ser um array de objetos com proposalId e newOrder.',
                ], Response::HTTP_BAD_REQUEST);
            }

            $this->orderingService->reorderProposals($data['reordering']);

            return $this->json([
                'success' => true,
                'message' => 'Propostas reordenadas com sucesso.',
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'Erro ao reordenar propostas: '.$e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
