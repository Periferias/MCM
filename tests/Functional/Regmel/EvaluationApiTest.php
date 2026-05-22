<?php

declare(strict_types=1);

namespace App\Tests\Functional\Regmel;

use App\Enum\EvaluationResultEnum;
use App\Enum\StatusProposalEnum;
use App\Tests\AbstractApiTestCase;

class EvaluationApiTest extends AbstractApiTestCase
{
    public function testGetProposalsAwaitingEvaluationRequiresAdminRole(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/proposals/awaiting-evaluation');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testEvaluateRequiresAdminRole(): void
    {
        $client = self::createClient();
        $proposalId = '550e8400-e29b-41d4-a716-446655440000';

        $client->request('POST', "/api/proposals/{$proposalId}/evaluate", [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'result' => 'Selecionada',
            'reason' => 'Test reason',
        ]));

        $this->assertResponseStatusCodeSame(401);
    }

    public function testEvaluateWithInvalidResult(): void
    {
        $client = self::apiClient(user: 'admin@regmel.com');
        $proposalId = '550e8400-e29b-41d4-a716-446655440000';

        $client->request('POST', "/api/proposals/{$proposalId}/evaluate", [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'result' => 'InvalidResult',
            'reason' => 'Test reason',
        ]));

        $this->assertResponseStatusCodeSame(400);
        $response = $this->getCurrentResponseArray();
        $this->assertFalse($response['success']);
        $this->assertStringContainsString('Resultado inválido', $response['message']);
    }

    public function testEvaluateMissingRequiredFields(): void
    {
        $client = self::apiClient(user: 'admin@regmel.com');
        $proposalId = '550e8400-e29b-41d4-a716-446655440000';

        $client->request('POST', "/api/proposals/{$proposalId}/evaluate", [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'result' => 'Selecionada',
        ]));

        $this->assertResponseStatusCodeSame(400);
        $response = $this->getCurrentResponseArray();
        $this->assertFalse($response['success']);
        $this->assertStringContainsString('obrigatórios', $response['message']);
    }

    public function testGetEvaluationNotFound(): void
    {
        $client = self::apiClient(user: 'admin@regmel.com');
        $proposalId = '550e8400-e29b-41d4-a716-446655440000';

        $client->request('GET', "/api/proposals/{$proposalId}/evaluation");

        $this->assertResponseStatusCodeSame(404);
        $response = $this->getCurrentResponseArray();
        $this->assertFalse($response['success']);
    }

    public function testGetEvaluationRequiresAdminRole(): void
    {
        $client = self::createClient();
        $proposalId = '550e8400-e29b-41d4-a716-446655440000';

        $client->request('GET', "/api/proposals/{$proposalId}/evaluation");

        $this->assertResponseStatusCodeSame(401);
    }

    public function testUploadEvaluationDocumentRequiresFile(): void
    {
        $client = self::apiClient(user: 'admin@regmel.com');
        $proposalId = '550e8400-e29b-41d4-a716-446655440000';

        $client->request('POST', "/api/proposals/{$proposalId}/evaluation/document", [], [], [
            'CONTENT_TYPE' => 'application/json',
        ]);

        $this->assertResponseStatusCodeSame(400);
        $response = $this->getCurrentResponseArray();
        $this->assertFalse($response['success']);
    }

    public function testGetProposalsAwaitingEvaluationSuccess(): void
    {
        $client = self::apiClient(user: 'admin@regmel.com');

        $client->request('GET', '/api/proposals/awaiting-evaluation?region=SP&state=São Paulo');

        $this->assertResponseStatusCodeSame(200);
        $response = $this->getCurrentResponseArray();
        $this->assertTrue($response['success']);
        $this->assertIsArray($response['data']);
        $this->assertArrayHasKey('total', $response);
    }
}
