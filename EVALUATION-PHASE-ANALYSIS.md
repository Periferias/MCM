# Análise de Implementação: Fase de Avaliação para Seleção de Propostas

**Branch:** `feat/evaluation-phase`  
**Data:** 13 de março de 2026  
**Objetivo:** Implementar uma fase de avaliação (selection phase) similar ao Mapas Culturais para registrar motivos de seleção/rejeição de propostas

---

## 📋 Demanda

**Requisito Principal:** Criar campo de motivo da seleção com os seguintes status:
- `SELECIONADA` ✅
- `NÃO SELECIONADA` ❌
- `CLASSIFICADA` 📊

---

## 🏗️ Visão Geral da Solução

A implementação seguirá o padrão existente na aplicação, criando uma fase de **Avaliação (Evaluation)** similar à fase de **Anuência (Agreement)** que já existe.

### Fluxo Proposto

```
Anuída pelo Município
        ↓
    [Fase de Avaliação] ← NOVO
        ↓
    (Opções de Resultado)
        ├─ SELECIONADA (com motivo/parecer)
        ├─ NÃO SELECIONADA (com motivo/parecer)
        └─ CLASSIFICADA (com classificação/ranking)
        ↓
    Status Final Definido
```

---

## 🔄 Status Existentes vs Novos

### Status Atuais (em `StatusProposalEnum`)
```php
enum StatusProposalEnum: string
{
    case ENVIADA = 'Enviada';
    case RECEBIDA = 'Recebida';
    case SEM_ADESAO = 'Sem Adesão do Município';
    case AGUARDANDO_AVALIACAO_ANUENCIA = 'Aguardando Validação da Anuência';
    case ANUIDA = 'Anuída pelo Município';
    case NAO_ANUIDA = 'Não Anuída pelo Município';
    case SELECIONADA = 'Selecionada';           // ← Já existe
    case NAO_SELECIONADA = 'Não Selecionada'; // ← Já existe
    case CLASSIFICADA = 'Classificada';        // ← Já existe
}
```

### Novo Status Intermediário Necessário
```php
case AGUARDANDO_AVALIACAO_SELECAO = 'Aguardando Avaliação da Seleção';
case AVALIACAO_SELECAO_REJEITADA = 'Avaliação da Seleção Rejeitada';
```

---

## 💾 Estrutura de Dados

### Campos no `extra_fields` da Proposal (Initiative Entity)

#### Campos de Anuência (Existente)
```php
'agreement_file'              // string - nome do arquivo
'agreement_status'            // string - 'submitted', 'approved', 'rejected'
'agreement_uploaded_at'       // datetime
'agreement_uploaded_by'       // UUID do usuário
'agreement_uploaded_by_name'  // nome do usuário
'agreement_rejection_reason'  // string - motivo da rejeição
'agreement_rejection_notes'   // text - notas da rejeição
```

#### Campos de Avaliação (NOVO)
```php
'evaluation_status'           // string - 'pending', 'completed', 'rejected'
'evaluation_document'         // string - nome do arquivo (opcional)
'evaluation_result'           // string - 'SELECIONADA', 'NAO_SELECIONADA', 'CLASSIFICADA'
'evaluation_reason'           // string - motivo/parecer (obrigatório)
'evaluation_notes'            // text - notas adicionais (opcional)
'evaluation_ranking'          // int - ranking/posição (apenas se CLASSIFICADA)
'evaluation_completed_at'     // datetime
'evaluation_completed_by'     // UUID do usuário
'evaluation_completed_by_name'// nome do usuário
```

---

## 📦 Implementação Técnica

### 1. Migrations (PostgreSQL)

#### Migration 1: Adicionar Índice de Status
```sql
-- Criar índice para melhor performance em queries de status
ALTER TABLE initiative ADD INDEX idx_extra_fields_status (
    (extra_fields->>'status')
);
```

Não é necessário alterar a schema de `extra_fields` pois usa JSONB.

### 2. Services

#### Novo Service: `ProposalEvaluationService`

**Localização:** `src/Regmel/Service/ProposalEvaluationService.php`

**Métodos Principais:**
```php
class ProposalEvaluationService implements ProposalEvaluationServiceInterface
{
    // Verificar se proposta pode ser avaliada
    public function canEvaluate(Uuid $proposalId): bool
    
    // Registrar avaliação (resultado final)
    public function evaluate(
        Uuid $proposalId,
        EvaluationResultEnum $result,
        string $reason,
        ?string $notes = null,
        ?int $ranking = null,
        ?UploadedFile $document = null
    ): void
    
    // Rejeitar avaliação (volta para "Aguardando Avaliação")
    public function rejectEvaluation(
        Uuid $proposalId,
        string $reason,
        ?string $notes = null
    ): void
    
    // Obter propostas aguardando avaliação
    public function getProposalsAwaitingEvaluation(
        ?string $region = null,
        ?string $state = null
    ): array
    
    // Obter avaliações de uma proposta
    public function getEvaluationHistory(Uuid $proposalId): array
}
```

**Interface:** `src/Regmel/Service/Interface/ProposalEvaluationServiceInterface.php`

### 3. Enum

#### Novo Enum: `EvaluationResultEnum`

**Localização:** `src/Regmel/Enum/EvaluationResultEnum.php`

```php
enum EvaluationResultEnum: string
{
    case SELECIONADA = 'Selecionada';
    case NAO_SELECIONADA = 'Não Selecionada';
    case CLASSIFICADA = 'Classificada';
}
```

### 4. DTOs

#### DTO: `EvaluationDTO`

**Localização:** `src/Regmel/DTO/EvaluationDTO.php`

```php
class EvaluationDTO
{
    public function __construct(
        public Uuid $proposalId,
        public EvaluationResultEnum $result,
        public string $reason,
        public ?string $notes = null,
        public ?int $ranking = null,
        public ?UploadedFile $document = null,
    ) {}
}
```

### 5. Controllers

#### API Controller: `EvaluationController`

**Localização:** `src/Controller/Api/Regmel/EvaluationController.php`

**Endpoints:**

```php
/**
 * GET /api/proposals/{id}/evaluation
 * Obter histórico de avaliação da proposta
 */
public function getEvaluation(Uuid $id): JsonResponse

/**
 * POST /api/proposals/{id}/evaluate
 * Registrar avaliação da proposta
 * 
 * Body:
 * {
 *     "result": "SELECIONADA|NAO_SELECIONADA|CLASSIFICADA",
 *     "reason": "string",
 *     "notes": "string (opcional)",
 *     "ranking": "int (apenas se CLASSIFICADA)"
 * }
 */
public function evaluate(Uuid $id, Request $request): JsonResponse

/**
 * POST /api/proposals/{id}/evaluation/document
 * Upload de documento de avaliação (ex: parecer)
 */
public function uploadEvaluationDocument(Uuid $id, Request $request): JsonResponse

/**
 * POST /api/proposals/{id}/evaluation/reject
 * Rejeitar avaliação (volta para aguardando)
 */
public function rejectEvaluation(Uuid $id, Request $request): JsonResponse

/**
 * GET /api/proposals/awaiting-evaluation
 * Listar propostas aguardando avaliação
 */
public function getProposalsAwaitingEvaluation(Request $request): JsonResponse
```

**Routes:** `config/routes/api/regmel/evaluation.yaml`

### 6. Listeners/Subscribers

#### EventListener: `EvaluationEventListener`

**Localização:** `src/EventListener/Regmel/EvaluationEventListener.php`

**Responsabilidades:**
- Registrar auditoria (MongoDB) quando avaliação é criada
- Enviar notificações por email quando avaliação é completada
- Validar transições de status

### 7. Validators

#### Validator: `ValidEvaluationResult`

```php
class ValidEvaluationResult implements ConstraintValidator
{
    // Validar se o ranking é obrigatório quando resultado é CLASSIFICADA
    // Validar se o reason não está vazio
    // Validar se a proposta está em estado válido para avaliação
}
```

### 8. Testes

#### Tests Unitários: `tests/Unit/Regmel/Service/ProposalEvaluationServiceTest.php`

```php
public function testCanEvaluateReturnsTrueWhenProposalIsAnuida()
public function testEvaluateWithSelecionada()
public function testEvaluateWithNaoSelecionada()
public function testEvaluateWithClassificada()
public function testEvaluateRequiresRankingWhenClassificada()
public function testRejectEvaluation()
public function testCannotEvaluateSameTwice()
```

#### Tests Funcionais: `tests/Functional/Regmel/EvaluationApiTest.php`

```php
public function testGetEvaluationHistoryEndpoint()
public function testEvaluateProposalEndpoint()
public function testUploadEvaluationDocument()
public function testRejectEvaluationEndpoint()
public function testGetProposalsAwaitingEvaluation()
```

---

## 🔐 Controle de Acesso

### Permissões Necessárias

Criar nova role/permissão em `SecurityVoter`:

```php
// Apenas administradores do sistema (estado/federal) podem avaliar
class EvaluationVoter extends Voter
{
    public const EVALUATE = 'EVALUATE';
    public const VIEW_EVALUATIONS = 'VIEW_EVALUATIONS';
    
    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::EVALUATE, self::VIEW_EVALUATIONS]);
    }
    
    protected function voteOnAttribute(
        string $attribute,
        mixed $subject,
        TokenInterface $token
    ): bool {
        // Verificar se usuário é admin de estado/federal
        // Ou se é gestor da avaliação designado
        return $subject->evaluator_id === $token->getUser()->getId();
    }
}
```

---

## 📊 Timeline de Implementação

### Fase 1: Setup e Estrutura Base (1-2 dias)
- ✅ Criar enums (`EvaluationResultEnum`)
- ✅ Criar DTOs (`EvaluationDTO`)
- ✅ Criar interfaces de serviço
- ✅ Criar estrutura de pastas/arquivos

### Fase 2: Core Service (2-3 dias)
- ✅ Implementar `ProposalEvaluationService`
- ✅ Adicionar métodos de validação
- ✅ Integrar com `FileService` para uploads
- ✅ Implementar lógica de transição de status

### Fase 3: API e Controllers (2-3 dias)
- ✅ Criar endpoints REST
- ✅ Implementar request/response normalization
- ✅ Adicionar autenticação e autorização
- ✅ Testes de integração da API

### Fase 4: Eventos e Auditoria (1-2 dias)
- ✅ Configurar event listeners
- ✅ Registrar em MongoDB (auditoria)
- ✅ Implementar notificações por email

### Fase 5: Testes e Documentação (2-3 dias)
- ✅ Testes unitários
- ✅ Testes funcionais
- ✅ Documentação OpenAPI/Swagger
- ✅ Guia de uso

### Fase 6: Integrações Frontend (1-2 dias)
- ✅ Interface de avaliação (formulário)
- ✅ Dashboard de propostas aguardando
- ✅ Histórico de avaliações

**Tempo Total Estimado:** 9-15 dias

---

## 🗂️ Estrutura de Arquivos a Criar

```
src/
├── Regmel/
│   ├── Service/
│   │   ├── ProposalEvaluationService.php          [NOVO]
│   │   └── Interface/
│   │       └── ProposalEvaluationServiceInterface.php [NOVO]
│   ├── Enum/
│   │   └── EvaluationResultEnum.php               [NOVO]
│   ├── DTO/
│   │   └── EvaluationDTO.php                      [NOVO]
│   └── Validator/
│       └── ValidEvaluationResult.php              [NOVO]
│
├── Controller/
│   └── Api/
│       └── Regmel/
│           └── EvaluationController.php           [NOVO]
│
├── EventListener/
│   └── Regmel/
│       └── EvaluationEventListener.php            [NOVO]
│
├── Security/
│   └── Voter/
│       └── EvaluationVoter.php                    [NOVO]
│
└── Event/
    └── Regmel/
        ├── EvaluationCompletedEvent.php           [NOVO]
        └── EvaluationRejectedEvent.php            [NOVO]

config/
├── routes/
│   └── api/
│       └── regmel/
│           └── evaluation.yaml                    [NOVO]
└── services.yaml                                  [MODIFICAR - registrar novos services]

tests/
├── Unit/
│   └── Regmel/
│       └── Service/
│           └── ProposalEvaluationServiceTest.php  [NOVO]
└── Functional/
    └── Regmel/
        └── EvaluationApiTest.php                  [NOVO]
```

---

## 🔄 Fluxo Detalhado de Estado

```
┌─────────────────────────────────────────────────────────────┐
│                      FLUXO COMPLETO                         │
└─────────────────────────────────────────────────────────────┘

1. ENVIADA
   └─> Proposta recebida pela plataforma

2. RECEBIDA
   └─> Anuência válida do município

3. AGUARDANDO_AVALIACAO_ANUENCIA
   └─> Aguardando validação do documento de anuência

4. ANUIDA / NAO_ANUIDA
   └─> Resultado da anuência

5. [NOVO] AGUARDANDO_AVALIACAO_SELECAO
   └─> Proposta pronta para avaliação de seleção
   
6. Avaliação Realizada:
   ├─> SELECIONADA ✅
   │   └─> Proposta aprovada e selecionada
   │       extra_fields['evaluation_result'] = 'SELECIONADA'
   │       extra_fields['evaluation_reason'] = "Motivo..."
   │
   ├─> NAO_SELECIONADA ❌
   │   └─> Proposta rejeitada
   │       extra_fields['evaluation_result'] = 'NAO_SELECIONADA'
   │       extra_fields['evaluation_reason'] = "Motivo da rejeição..."
   │
   └─> CLASSIFICADA 📊
       └─> Proposta classificada com ranking
           extra_fields['evaluation_result'] = 'CLASSIFICADA'
           extra_fields['evaluation_ranking'] = 1, 2, 3...
           extra_fields['evaluation_reason'] = "Parecer..."

┌────────────────────────────────────────────────┐
│ [NOVO] AVALIACAO_SELECAO_REJEITADA             │
│                                                │
│ Avaliação foi rejeitada e volta para:          │
│ AGUARDANDO_AVALIACAO_SELECAO                   │
│                                                │
│ Permite re-avaliação com novo parecer          │
└────────────────────────────────────────────────┘
```

---

## 📝 Exemplos de Payload

### POST /api/proposals/{id}/evaluate

#### Selecionada
```json
{
  "result": "SELECIONADA",
  "reason": "Proposta atende todos os critérios. Projeto bem estruturado com impacto comunitário significativo.",
  "notes": "Recomendado para aprovação imediata"
}
```

#### Não Selecionada
```json
{
  "result": "NAO_SELECIONADA",
  "reason": "Documentação incompleta. Faltam comprovantes de posse do imóvel.",
  "notes": "Pode ser reapresentada após regularização"
}
```

#### Classificada
```json
{
  "result": "CLASSIFICADA",
  "reason": "Proposta de excelente qualidade. Projeto inovador com potencial de replicação.",
  "ranking": 3,
  "notes": "Posição dentro do ranking estadual de 50 seleções"
}
```

### Response
```json
{
  "success": true,
  "message": "Avaliação registrada com sucesso",
  "data": {
    "proposalId": "uuid",
    "status": "SELECIONADA",
    "evaluation": {
      "result": "SELECIONADA",
      "reason": "...",
      "notes": "...",
      "completedAt": "2026-03-13T15:30:45Z",
      "completedBy": {
        "id": "uuid",
        "name": "João Silva"
      }
    }
  }
}
```

---

## 🗄️ Mudanças em `extra_fields` - Exemplo Completo

```php
$extraFields = [
    // Campos existentes
    'status' => 'Anuída pelo Município',
    'agreement_file' => 'uuid_agreement_v01.pdf',
    'agreement_status' => 'approved',
    'agreement_uploaded_at' => '2026-03-10 14:30:00',
    
    // [NOVO] Campos de avaliação
    'evaluation_status' => 'completed',
    'evaluation_result' => 'SELECIONADA',
    'evaluation_reason' => 'Proposta atende critérios de elegibilidade...',
    'evaluation_notes' => 'Prioritário - impacto social comprovado',
    'evaluation_ranking' => null, // null se não for CLASSIFICADA
    'evaluation_completed_at' => '2026-03-13 15:30:00',
    'evaluation_completed_by' => 'uuid-do-avaliador',
    'evaluation_completed_by_name' => 'Maria Silva',
];
```

---

## 🔔 Notificações por Email

### Template: `EvaluationCompleted`
- **Para:** Responsável da proposta
- **Assunto:** "Sua proposta foi avaliada"
- **Conteúdo:** Resultado, motivo, próximos passos

### Template: `EvaluationRejected`
- **Para:** Responsável da proposta
- **Assunto:** "Avaliação requerida - Proposta pendente"
- **Conteúdo:** Motivo da rejeição, instruções para reapresentação

---

## 📚 Referências de Implementação Similar

### Fase de Anuência Existente
**Arquivo:** `src/Regmel/Service/ProposalAgreementService.php`

**Pattern a Seguir:**
1. Validação de estado anterior
2. Upload de arquivo com versionamento
3. Transição de status atômica
4. Registro em `extra_fields`
5. Notificação por email
6. Event dispatchado para auditoria

---

## ✅ Checklist de Implementação

### Preparação
- [ ] Criar nova branch `feat/evaluation-phase`
- [ ] Criar este documento de análise
- [ ] Discutir com time de requisitos

### Estrutura Base
- [ ] Criar `EvaluationResultEnum`
- [ ] Criar `EvaluationDTO`
- [ ] Criar interface `ProposalEvaluationServiceInterface`
- [ ] Criar eventos (`EvaluationCompletedEvent`, `EvaluationRejectedEvent`)

### Service
- [ ] Implementar `ProposalEvaluationService`
- [ ] Validações internas
- [ ] Integração com `FileService`
- [ ] Integração com `EmailService`
- [ ] Transações atômicas (Doctrine)

### API
- [ ] Criar `EvaluationController`
- [ ] Registrar rotas
- [ ] Request/Response DTO normalization
- [ ] Autenticação e autorização (Voters)
- [ ] Rate limiting

### Eventos e Listeners
- [ ] Criar listeners para events
- [ ] Registrar no `EventListener`
- [ ] Auditoria em MongoDB

### Testes
- [ ] Testes unitários do service
- [ ] Testes dos validators
- [ ] Testes funcionais da API
- [ ] Coverage > 80%

### Documentação
- [ ] OpenAPI/Swagger
- [ ] README da feature
- [ ] Exemplos de uso

### Frontend (se necessário)
- [ ] Formulário de avaliação
- [ ] Dashboard de propostas
- [ ] Histórico de avaliações

---

## 📌 Considerações Especiais

### Performance
- **Índices:** Adicionar índice em `extra_fields->>'evaluation_status'`
- **Paginação:** Implementar em endpoints de listagem
- **Cache:** Considerar cache de "propostas aguardando avaliação"

### Segurança
- **Auditoria:** Todas as mudanças registradas em MongoDB
- **Imutabilidade:** Avaliações completadas não podem ser alteradas (apenas rejeitadas)
- **RBAC:** Apenas usuários autorizados podem avaliar
- **Validação:** Input validation em todos os endpoints

### Escalabilidade
- **Padrão de Serviço:** Facilita futuros stages (ex: recurso de avaliação)
- **Event-Driven:** Permite hooks para notificações, webhooks, etc.
- **Histórico:** Monitorar crescimento de dados em MongoDB

---

## 🎯 Próximos Passos

1. **Validação com Product Owner:** Confirmar requisitos
2. **Design Review:** Com arquiteto da aplicação
3. **Sprint Planning:** Estimar tasks e distribuir
4. **Kick-off:** Iniciar desenvolvimento iterativo

---

## 📞 Dúvidas Frequentes

**P: Como diferencia CLASSIFICADA de SELECIONADA?**  
R: SELECIONADA é binário (sim/não). CLASSIFICADA inclui um ranking/posição.

**P: Pode haver múltiplas avaliações?**  
R: Sim, via rejeição. Uma avaliação rejeitada volta para "Aguardando Avaliação".

**P: Onde armazenar pareceres longos?**  
R: Use o campo `notes` em JSONB ou crie um documento separado em MongoDB.

**P: Precisa de approval/revisão das avaliações?**  
R: Implementar em próxima fase se necessário (AdditionalReview status).

---

## 📄 Última Atualização
**Data:** 13/03/2026  
**Status:** Documento de Análise - Pronto para Implementação  
**Branch:** `feat/evaluation-phase`
