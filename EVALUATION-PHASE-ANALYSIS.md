# Análise de Implementação: Fase de Avaliação para Seleção de Propostas

**Branch:** `feat/evaluation-phase`  
**Data:** 17 de março de 2026 (REVISADO)  
**Objetivo:** Implementar uma fase de avaliação (selection phase) similar ao Mapas Culturais para registrar motivos de seleção/rejeição de propostas

---

## 🚀 QUICK START (Leia Primeiro)

### Mudanças em 5 Linhas
| # | Mudança | Impacto |
|---|---------|--------|
| 1️⃣ | Apenas `ROLE_ADMIN` avalia | ✅ Simplificou controle de acesso |
| 2️⃣ | Sem notificações de email | ✅ Removeu dependências externas |
| 3️⃣ | Sem alterações de BD | ✅ Deployment mais seguro (JSONB) |
| 4️⃣ | Apenas uma avaliação | ✅ Dados imutáveis e consistentes |
| 5️⃣ | ROLE_EVALUATOR_VIEWER planejado | ✅ Roadmap para fase futura |

### O Que NÃO Fazer
```
❌ Não criar EvaluationVoter customizado
❌ Não implementar notificações de email
❌ Não criar migrations DDL
❌ Não implementar rejeição de avaliação
❌ Não criar nova role nesta fase
```

### Timeline
- **Antes:** 9-15 dias
- **Depois:** 8-12 dias (15% mais rápido)

---

## 📋 Demanda

**Requisito Principal:** Criar campo de motivo da seleção com os seguintes status:
- `SELECIONADA` ✅ (Aprovada, recebe recurso)
- `NÃO SELECIONADA` ❌ (Rejeitada)
- `CLASSIFICADA` 📊 (Suplente, sem recurso imediato)

**Restrições Importantes:**
- ✋ Remover controle de acesso complexo - apenas `ROLE_ADMIN` pode avaliar
- ✋ NÃO enviar notificações por email
- ✋ NÃO mexer no banco de dados (usar JSONB existente)
- ✅ Uma proposta **anuída pelo município** pode ser avaliada
- ✅ Uma **única avaliação** por proposta nesta fase
- ✅ Classificada = nomenclatura "Classificada", mas funcionalmente suplente

---

## 🏗️ Visão Geral da Solução

A implementação seguirá o padrão existente na aplicação, criando uma fase de **Avaliação (Evaluation)** similar à fase de **Anuência (Agreement)** que já existe.

### Fluxo Proposto

```
Anuída pelo Município ✅
        ↓
    [Fase de Avaliação - Uma Única Avaliação] ← NOVO
        ↓
    (Opções de Resultado - Status Final)
        ├─ SELECIONADA (aprovada, recebe recurso)
        ├─ NÃO SELECIONADA (rejeitada)
        └─ CLASSIFICADA (suplente, sem recurso imediato)
        ↓
    Status Imutável (não pode ser alterado)
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
    case ANUIDA = 'Anuída pelo Município';      // ← Pode ser avaliada a partir daqui
    case NAO_ANUIDA = 'Não Anuída pelo Município';
    case SELECIONADA = 'Selecionada';          // ← Aprovada, recebe recurso
    case NAO_SELECIONADA = 'Não Selecionada';  // ← Rejeitada
    case CLASSIFICADA = 'Classificada';        // ← Suplente (não recebe recurso imediato)
}
```

**Clarificação de Nomenclatura:**
- **SELECIONADA:** Proposta aprovada e selecionada para receber recurso
- **CLASSIFICADA:** Proposta em posição de suplente (nomenclatura "Classificada", mas funcionalmente suplente)
- **NÃO SELECIONADA:** Proposta rejeitada, não recebe recurso

### Novo Status Intermediário Necessário
```php
case AGUARDANDO_AVALIACAO_SELECAO = 'Aguardando Avaliação da Seleção';
```

---

## 💾 Estrutura de Dados

### Campos no `extra_fields` da Proposal (Initiative Entity)

**IMPORTANTE:** Todos os campos de avaliação são armazenados no JSONB `extra_fields` existente. **Nenhuma alteração de schema SQL necessária.**

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
'evaluation_status'           // string - 'pending', 'completed'
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

### 1. Estrutura do Banco de Dados

**Nota Importante:** Não há alterações na schema do banco de dados. Todos os campos de avaliação são armazenados no campo JSONB `extra_fields` da tabela `initiative`.

Não é necessário criar migrations DDL - apenas usar a estrutura existente.

### 2. Services

#### Novo Service: `ProposalEvaluationService`

**Localização:** `src/Regmel/Service/ProposalEvaluationService.php`

**Métodos Principais:**
```php
class ProposalEvaluationService implements ProposalEvaluationServiceInterface
{
    // Verificar se proposta pode ser avaliada
    public function canEvaluate(Uuid $proposalId): bool
    
    // Registrar avaliação (resultado final - uma única avaliação)
    public function evaluate(
        Uuid $proposalId,
        EvaluationResultEnum $result,
        string $reason,
        ?string $notes = null,
        ?int $ranking = null,
        ?UploadedFile $document = null
    ): void
    
    // Obter propostas aguardando avaliação
    public function getProposalsAwaitingEvaluation(
        ?string $region = null,
        ?string $state = null
    ): array
    
    // Obter resultado da avaliação de uma proposta (uma única avaliação)
    public function getEvaluation(Uuid $proposalId): ?array
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
 * Obter resultado da avaliação da proposta
 */
#[IsGranted('ROLE_ADMIN')]
public function getEvaluation(Uuid $id): JsonResponse

/**
 * POST /api/proposals/{id}/evaluate
 * Registrar avaliação da proposta (uma única avaliação por proposta nesta fase)
 * 
 * Body:
 * {
 *     "result": "SELECIONADA|NAO_SELECIONADA|CLASSIFICADA",
 *     "reason": "string",
 *     "notes": "string (opcional)",
 *     "ranking": "int (apenas se CLASSIFICADA)"
 * }
 */
#[IsGranted('ROLE_ADMIN')]
public function evaluate(Uuid $id, Request $request): JsonResponse

/**
 * POST /api/proposals/{id}/evaluation/document
 * Upload de documento de avaliação (ex: parecer)
 */
#[IsGranted('ROLE_ADMIN')]
public function uploadEvaluationDocument(Uuid $id, Request $request): JsonResponse

/**
 * GET /api/proposals/awaiting-evaluation
 * Listar propostas aguardando avaliação
 */
#[IsGranted('ROLE_ADMIN')]
public function getProposalsAwaitingEvaluation(Request $request): JsonResponse
```

**Routes:** `config/routes/api/regmel/evaluation.yaml`

### 6. Listeners/Subscribers

#### EventListener: `EvaluationEventListener`

**Localização:** `src/EventListener/Regmel/EvaluationEventListener.php`

**Responsabilidades:**
- Registrar auditoria (MongoDB) quando avaliação é criada
- Validar transições de status

**Nota:** Notificações por email não são necessárias nesta fase

### 7. Validators

#### Validator: `ValidEvaluationResult`

```php
class ValidEvaluationResult implements ConstraintValidator
{
    // Validar se o ranking é obrigatório quando resultado é CLASSIFICADA
    // Validar se o reason não está vazio
    // Validar se a proposta está em estado válido para avaliação (ANUIDA)
    // Validar se a proposta ainda não foi avaliada
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
public function testCannotEvaluateTwice()
public function testCanOnlyEvaluateFromAnuidaStatus()
```

#### Tests Funcionais: `tests/Functional/Regmel/EvaluationApiTest.php`

```php
public function testGetEvaluationEndpoint()
public function testEvaluateProposalEndpoint()
public function testUploadEvaluationDocument()
public function testGetProposalsAwaitingEvaluation()
```

---

## 🔐 Controle de Acesso

### Permissões Necessárias

Apenas **administradores do sistema** (role `ROLE_ADMIN`) podem avaliar propostas.

**Nota:** Sem criação de nova role nesta fase. Utiliza controle de acesso existente via `#[IsGranted('ROLE_ADMIN')]`.

Todos os endpoints de avaliação exigem:
```php
#[IsGranted('ROLE_ADMIN')]
public function evaluate(Uuid $id, Request $request): JsonResponse
```

---

## 🔐 Plano para `ROLE_EVALUATOR_VIEWER` (Fase Futura)

### Objetivo
Criar um perfil que visualiza apenas propostas com status:
- `SELECIONADA` ✅
- `CLASSIFICADA` 📊

Sem permissão de alterar nada.

### Escopo (para fase futura)
- [ ] Criar role `ROLE_EVALUATOR_VIEWER`
- [ ] Implementar voter para restringir visualização
- [ ] Endpoints de leitura apenas (GET)
- [ ] Sem acesso a formulário de avaliação
- [ ] Documentação de acesso

### Endpoints Disponíveis (Futura)
- `GET /api/proposals` (apenas SELECIONADA + CLASSIFICADA)
- `GET /api/proposals/{id}` (apenas se SELECIONADA ou CLASSIFICADA)
- `GET /api/proposals/{id}/evaluation` (leitura)

### Endpoints Bloqueados (Futura)
- `POST /api/proposals/{id}/evaluate`
- `POST /api/proposals/{id}/evaluation/document`
- Qualquer endpoint que modifique dados

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
- ✅ Adicionar autenticação e autorização (ROLE_ADMIN)
- ✅ Testes de integração da API

### Fase 4: Eventos e Auditoria (1 dia)
- ✅ Configurar event listeners
- ✅ Registrar em MongoDB (auditoria)

### Fase 5: Testes e Documentação (2-3 dias)
- ✅ Testes unitários
- ✅ Testes funcionais
- ✅ Documentação OpenAPI/Swagger
- ✅ Guia de uso

### Fase 6: Integrações Frontend (1-2 dias)
- ✅ Interface de avaliação (formulário)
- ✅ Dashboard de propostas aguardando
- ✅ Visualização de resultado da avaliação

**Tempo Total Estimado:** 8-12 dias

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
└── EventListener/
    └── Regmel/
        └── EvaluationEventListener.php            [NOVO]

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

4. ANUIDA ✅
   └─> PRONTA PARA AVALIAÇÃO (status final desta etapa)

5. [NOVO] AGUARDANDO_AVALIACAO_SELECAO
   └─> Proposta pronta para avaliação de seleção
   
6. Avaliação Realizada (Uma Única - Status Final Imutável):
   ├─> SELECIONADA ✅ (Status Final)
   │   └─> Proposta aprovada e selecionada para receber recurso
   │       extra_fields['evaluation_result'] = 'SELECIONADA'
   │       extra_fields['evaluation_reason'] = "Motivo..."
   │       extra_fields['evaluation_completed_at'] = timestamp
   │
   ├─> NAO_SELECIONADA ❌ (Status Final)
   │   └─> Proposta rejeitada, não recebe recurso
   │       extra_fields['evaluation_result'] = 'NAO_SELECIONADA'
   │       extra_fields['evaluation_reason'] = "Motivo da rejeição..."
   │       extra_fields['evaluation_completed_at'] = timestamp
   │
   └─> CLASSIFICADA 📊 (Status Final - Suplente)
       └─> Proposta em posição de suplente (não recebe recurso imediato)
           extra_fields['evaluation_result'] = 'CLASSIFICADA'
           extra_fields['evaluation_ranking'] = 1, 2, 3...
           extra_fields['evaluation_reason'] = "Parecer..."
           extra_fields['evaluation_completed_at'] = timestamp

⚠️ IMPORTANTE: Uma vez avaliada, a proposta não retorna para
"Aguardando Avaliação". O resultado é imutável. Rejeição de 
avaliação será implementada em fase futura se necessário.
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

#### Classificada (Suplente)
```json
{
  "result": "CLASSIFICADA",
  "reason": "Proposta de boa qualidade. Projeto inovador com potencial de replicação.",
  "ranking": 3,
  "notes": "Posição 3 no ranking estadual. Suplente para eventual substituição"
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
      "completedAt": "2026-03-17T15:30:45Z",
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
    
    // [NOVO] Campos de avaliação (Uma única avaliação)
    'evaluation_status' => 'completed',
    'evaluation_result' => 'SELECIONADA',
    'evaluation_reason' => 'Proposta atende critérios de elegibilidade...',
    'evaluation_notes' => 'Prioritário - impacto social comprovado',
    'evaluation_ranking' => null, // null se não for CLASSIFICADA
    'evaluation_completed_at' => '2026-03-17 15:30:00',
    'evaluation_completed_by' => 'uuid-do-avaliador',
    'evaluation_completed_by_name' => 'Maria Silva',
];
```

---

## 📚 Referências de Implementação Similar

### Fase de Anuência Existente
**Arquivo:** `src/Regmel/Service/ProposalAgreementService.php`

**Pattern a Seguir:**
1. Validação de estado anterior
2. Upload de arquivo com versionamento
3. Transição de status atômica
4. Registro em `extra_fields`
5. Event dispatchado para auditoria (sem email)

---

## ✅ Checklist de Implementação

### Preparação
- [ ] Criar nova branch `feat/evaluation-phase`
- [ ] Ler este documento completo
- [ ] Discutir com time de requisitos

### Estrutura Base
- [ ] Criar `EvaluationResultEnum`
- [ ] Criar `EvaluationDTO`
- [ ] Criar interface `ProposalEvaluationServiceInterface`

### Service
- [ ] Implementar `ProposalEvaluationService`
- [ ] Validações internas
- [ ] Integração com `FileService`
- [ ] Transações atômicas (Doctrine)
- [ ] Uma avaliação por proposta (sem rejeição nesta fase)

### API
- [ ] Criar `EvaluationController`
- [ ] Registrar rotas
- [ ] Request/Response DTO normalization
- [ ] Autenticação e autorização (apenas ROLE_ADMIN)
- [ ] Rate limiting

### Eventos e Listeners
- [ ] Criar listeners para events
- [ ] Registrar no `EventListener`
- [ ] Auditoria em MongoDB
- [ ] **Sem** notificações por email

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
- [ ] Visualização de resultado da avaliação

---

## 📌 Considerações Especiais

### Performance
- **JSONB:** Utilizar campo `extra_fields` existente (JSONB) - sem alterações de schema
- **Índices:** Considerar índice em `extra_fields->>'evaluation_status'` se performance necessário
- **Paginação:** Implementar em endpoints de listagem
- **Cache:** Considerar cache de "propostas aguardando avaliação"

### Segurança
- **Auditoria:** Todas as mudanças registradas em MongoDB
- **Imutabilidade:** Avaliações completadas são finais e não podem ser alteradas ou rejeitadas
- **RBAC:** Apenas administradores (ROLE_ADMIN) podem avaliar
- **Validação:** Input validation em todos os endpoints
- **Uma Avaliação:** Cada proposta pode ser avaliada apenas uma vez nesta fase
- **Sem Banco de Dados:** Nenhuma alteração em schema SQL

### Escalabilidade
- **Padrão de Serviço:** Facilita futuros stages (ex: recurso de avaliação)
- **Event-Driven:** Permite hooks para webhooks, etc. (sem email nesta fase)
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
R: SELECIONADA = aprovada e recebe recurso. CLASSIFICADA = suplente, sem recurso imediato, mas com ranking.

**P: Pode haver múltiplas avaliações?**  
R: Não nesta fase. Cada proposta é avaliada apenas uma vez. Rejeição de avaliação será implementada em futuro.

**P: Onde armazenar pareceres longos?**  
R: Use o campo `notes` em JSONB ou crie um documento separado em MongoDB.

**P: Precisa de approval/revisão das avaliações?**  
R: Implementar em próxima fase se necessário (AdditionalReview status).

**P: Quem pode visualizar as propostas selecionadas/classificadas?**  
R: Será criada uma role `ROLE_EVALUATOR_VIEWER` em fase futura. Por enquanto, apenas admin.

---

## 📄 Histórico de Revisões

| Data | Status | Mudanças |
|------|--------|----------|
| 13/03/2026 | Original | Documento inicial |
| 17/03/2026 | Revisado | 7 mudanças principais aplicadas |

### Mudanças Realizadas (17/03/2026):
- ✅ Remover controle de acesso complexo - apenas ROLE_ADMIN
- ✅ Remover notificações por email
- ✅ Clarificar que não altera banco de dados (usa JSONB existente)
- ✅ Deixar claro que proposta anuída pode ser avaliada
- ✅ Clarificar que CLASSIFICADA = suplente mas com nome "Classificada"
- ✅ Implementar apenas uma avaliação por proposta (sem rejeição nesta fase)
- ✅ Planejar futura role ROLE_EVALUATOR_VIEWER para visualização
- ✅ Consolidar tudo em um único documento (mais prático e fácil de manter)

---

**Status Final:** ✅ Documento Completo e Consolidado - Pronto para Desenvolvimento
