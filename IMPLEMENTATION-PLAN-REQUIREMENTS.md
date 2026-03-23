# 📋 Plano de Implementação - Avaliação, Classificação e Ordenação de Propostas

**Data:** 23 de março de 2026  
**Status Geral:** 49% implementado  
**Próxima Etapa:** Completar requisitos críticos (Ordem, Caixa, Reordenação)

---

## 🎯 Resumo Executivo

### ✅ O que já está pronto:
- ✅ Anuência do município (validação backend)
- ✅ Classificação de propostas (status + motivo obrigatório)
- ✅ Avaliação única por proposta (imutável)
- ✅ Fluxo geral: Cadastro → Anuência → Avaliação → Resultado

### ❌ O que falta implementar:
- ❌ Perfil de acesso "Caixa" (role + filtros de visualização)
- ❌ Campo persistido de ordem de prioridade
- ❌ Interface de reordenação manual (drag-and-drop)
- ❌ Validação de integridade de sequência

---

## 📊 Status Detalhado dos Requisitos

### 1. ✅ Anuência do Município (70% - PARCIAL)

**Implementado:**
- ✅ Validação backend: proposta só avaliável após anuência
- ✅ Status `ANUIDA` libera para avaliação
- ✅ Status `NAO_ANUIDA` bloqueia avaliação
- ✅ Motivo da não-anuência persistido em `extra_fields`

**Não implementado:**
- ❌ UI visual de bloqueio (propostas "ocultas ou somente-leitura")
- ❌ Indicador visual em listagens

**Arquivos:** 
- `src/Enum/StatusProposalEnum.php`
- `src/Regmel/Service/ProposalEvaluationService.php`

---

### 2. ✅ Classificação das Propostas (95% - IMPLEMENTADO)

**Implementado:**
- ✅ Três status finais: `SELECIONADA`, `CLASSIFICADA`, `NAO_SELECIONADA`
- ✅ Motivo obrigatório para todos (validação implementada)
- ✅ Campos persistidos:
  - `evaluation_result` → status final
  - `evaluation_reason` → motivo (obrigatório)
  - `evaluation_notes` → notas (opcional)
  - `evaluation_ranking` → ranking para suplentes
  - `evaluation_completed_at` → timestamp
  - `evaluation_completed_by_name` → quem avaliou

**Não implementado:**
- ❌ Nada relevante

**Arquivos:**
- `src/Regmel/Enum/EvaluationResultEnum.php`
- `src/Regmel/Service/ProposalEvaluationService.php`
- `src/Controller/Api/EvaluationApiController.php`

---

### 3. ❌ Perfil de Acesso: Caixa (0% - NÃO IMPLEMENTADO)

**Necessário implementar:**
- ❌ Role `ROLE_CAIXA` não existe
- ❌ Permissões: visualizar apenas `SELECIONADA` + `CLASSIFICADA`
- ❌ Bloqueio: não visualizar `NAO_SELECIONADA` + `AGUARDANDO_AVALIACAO_SELECAO`
- ❌ Modo somente-leitura obrigatório
- ❌ Voter/Authorization customizado

**Roles Existentes:**
- `ROLE_ADMIN` (pode avaliar)
- `ROLE_MANAGER`
- `ROLE_COMPANY`
- `ROLE_MUNICIPALITY`
- `ROLE_SUPPORT`
- `ROLE_USER`

**Impacto:** Alto - CRÍTICO

---

### 4. ❌ Ordem de Prioridade (0% - NÃO IMPLEMENTADO)

**Necessário implementar:**
- ❌ Campo persistido `ordem_prioridade` / `evaluation_ranking`
  - Atualmente: apenas existe para `CLASSIFICADAS` em `extra_fields`
  - Falta: não está para `SELECIONADAS`
- ❌ Sequência obrigatória (1, 2, 3...)
- ❌ Unicidade (sem duplicidade)
- ❌ Integridade (sem quebras na sequência)
- ❌ Endpoint para editar manualmente
- ❌ Interface visual

**Detalhes Técnicos:**
- Campo: `extra_fields['ordem_prioridade']` (JSONB - sem migration DDL necessária)
- Escopo: Propostas com status `SELECIONADA` ou `CLASSIFICADA`
- Permissão: `ROLE_ADMIN`

**Impacto:** Alto - CRÍTICO

---

### 5. ❌ Reordenação Manual (Drag-and-Drop) (0% - NÃO IMPLEMENTADO)

**Necessário implementar:**
- ❌ Interface frontend com drag-and-drop
- ❌ Endpoint POST para reordenar
- ❌ Lógica de recalcular sequência
- ❌ Validação de integridade após reordenação
- ❌ Atualização em tempo real
- ❌ Persistência ordenada em listagem
- ❌ Testes

**Dependência:** Requisito #4 deve ser feito antes

**Impacto:** Alto - CRÍTICO

---

### 6. ⚠️ Regras de Integridade (50% - PARCIAL)

| Regra | Status | Implementação |
|-------|--------|---------------|
| Não avaliar sem anuência | ✅ | ProposalEvaluationService#canEvaluate() |
| Motivo obrigatório | ✅ | ProposalEvaluationService#evaluate() |
| Ordem para selecionadas/classificadas | ❌ | Falta campo + validação |
| Sem duplicidade de ordem | ❌ | Falta validação |
| Sem quebra de sequência | ❌ | Falta validação |
| Reordenação atualiza afetados | ❌ | Falta endpoint |

**Impacto:** Médio - IMPORTANTE

---

### 7. ⚠️ Auditoria (60% - PARCIAL)

**Implementado:**
- ✅ Registra usuário: `evaluation_completed_by_name`
- ✅ Data/hora: `evaluation_completed_at`
- ✅ Timeline em MongoDB: `InitiativeTimeline`
- ✅ EventListener: `EvaluationEventListener`

**Não implementado:**
- ❌ Histórico de mudanças de ordem
- ❌ Log de quem reordenou

**Impacto:** Baixo - DESEJÁVEL

---

### 8. ✅ Fluxo Geral (95% - IMPLEMENTADO)

**Implementado:**
- ✅ Cadastro de proposta
- ✅ Anuência do município
- ✅ Liberação para avaliação
- ✅ Definição de status + motivo
- ✅ Armazenamento de resultado

**Não implementado:**
- ❌ Ordenação de prioridade
- ❌ Ajuste manual de ordem
- ❌ Visualização por perfil Caixa

---

## 🛠️ Plano de Implementação (Fases)

### 📍 FASE 1: Fundação (Semana 1)

#### 1.1 - Criar Role CAIXA
**Arquivo:** `src/Enum/UserRolesEnum.php`
```php
case ROLE_CAIXA = 'ROLE_CAIXA';
```
**Tempo:** 30 min
**Testes:** Unit (enum values)

#### 1.2 - Implementar Campo de Ordem Persistido
**Arquivo:** `src/Regmel/Service/ProposalEvaluationService.php`
- Alterar método `evaluate()` para:
  - Aceitar parâmetro `$order` (int | null)
  - Persistir em `extra_fields['ordem_prioridade']` se SELECIONADA
  - Persistir em `extra_fields['evaluation_ranking']` se CLASSIFICADA
  - Validar sequência (1 baseada, sem quebras)

**Tempo:** 2h
**Testes:** Unit + Functional

#### 1.3 - Criar Voter para ROLE_CAIXA
**Arquivo:** `src/Security/Voter/ProposalViewer.php` (novo)
- Restrições:
  - Visualizar: apenas `SELECIONADA` + `CLASSIFICADA`
  - Bloquear: `NAO_SELECIONADA`, `AGUARDANDO_AVALIACAO_SELECAO`
  - Somente-leitura (sem POST/PUT/DELETE)

**Tempo:** 1.5h
**Testes:** Unit + Security

---

### 📍 FASE 2: Backend APIs (Semana 1-2)

#### 2.1 - Endpoint: Listar Propostas com Ordem
**Arquivo:** `src/Controller/Api/EvaluationApiController.php`
```
GET /api/proposals/ordered
  ?status=SELECIONADA|CLASSIFICADA
  &region=...
  &state=...
Query params para filtro
Response: Lista ordenada por ordem_prioridade
```
**Tempo:** 1h
**Testes:** Functional API

#### 2.2 - Endpoint: Reordenar Propostas
**Arquivo:** `src/Controller/Api/EvaluationApiController.php`
```
POST /api/proposals/reorder
Body: {
  "reordering": [
    { "proposalId": "uuid-1", "newOrder": 3 },
    { "proposalId": "uuid-2", "newOrder": 1 },
    { "proposalId": "uuid-3", "newOrder": 2 }
  ]
}
```
**Lógica:**
- Validar que todas as IDs existem e estão em status válido
- Recalcular sequência (1-based)
- Atualizar em transação (all-or-nothing)
- Registrar em auditoria

**Tempo:** 2h
**Testes:** Functional API + Unit

#### 2.3 - Service: ProposalOrderingService
**Arquivo:** `src/Regmel/Service/ProposalOrderingService.php` (novo)
```php
public function reorderProposals(array $reordering): void
public function getProposalsOrdered(
    ?string $status = null,
    ?string $region = null
): array
private function validateSequenceIntegrity(array $proposals): bool
```
**Tempo:** 2h
**Testes:** Unit

---

### 📍 FASE 3: Validação de Integridade (Semana 2)

#### 3.1 - Validator: Sequência Única
**Arquivo:** `src/Validator/Constraint/UniqueProposalOrder.php` (novo)
- Validar que não há duplicidade de ordem
- Validar que não há quebras na sequência
- Validar que ordem >= 1

**Tempo:** 1.5h
**Testes:** Unit

#### 3.2 - Constraint: Aplicar a Reordenação
**Arquivo:** `src/Regmel/Service/ProposalOrderingService.php`
- Usar validator ao reordenar
- Lançar exceção se validação falhar

**Tempo:** 45 min
**Testes:** Unit

---

### 📍 FASE 4: Frontend Interface (Semana 2-3)

#### 4.1 - Template: Lista Ordenável
**Arquivo:** `templates/regmel/admin/proposal/list-ordered.html.twig` (novo)
- Exibir propostas com status SELECIONADA + CLASSIFICADA
- Mostrar coluna "Ordem" (editável)
- Exibir indicador drag-and-drop

**Tempo:** 2h

#### 4.2 - JavaScript: Drag-and-Drop
**Arquivo:** `assets/js/regmel/proposal-ordering.js` (novo)
- Usar SortableJS ou similar
- Interceptar onChange
- POST para `/api/proposals/reorder`
- Validar resposta + feedback visual

**Tempo:** 2.5h

#### 4.3 - Controller Web: ListOrdered
**Arquivo:** `src/Regmel/Controller/Web/Admin/ProposalOrderingController.php` (novo)
```php
#[Route('/painel/admin/propostas/ordenar', 
        name: 'admin_regmel_proposal_order')]
public function listOrdered(Request $request): Response
```
**Tempo:** 1h
**Testes:** Functional

---

### 📍 FASE 5: Auditoria & Logging (Semana 3)

#### 5.1 - EventListener: Registrar Reordenação
**Arquivo:** `src/EventListener/Regmel/ProposalOrderingEventListener.php` (novo)
- Registrar em `ProposalOrderingTimeline` (novo Document)
- Campo: usuário, data, lista anterior, lista nova

**Tempo:** 1.5h
**Testes:** Unit

#### 5.2 - Endpoint: Histórico de Reordenação
**Arquivo:** `src/Controller/Api/EvaluationApiController.php`
```
GET /api/proposals/ordering-history
```
**Tempo:** 1h
**Testes:** Functional

---

### 📍 FASE 6: Testes & QA (Semana 3-4)

#### 6.1 - Testes Unitários
- `ProposalOrderingServiceTest.php`
- `ProposalOrderingValidatorTest.php`
- `UniqueProposalOrderConstraintTest.php`

**Tempo:** 2h

#### 6.2 - Testes Funcionais
- `ProposalOrderingApiTest.php`
- `ProposalOrderingWebTest.php`

**Tempo:** 2.5h

#### 6.3 - Testes E2E (Cypress)
- `cypress/regmel/e2e/admin/proposal/ordering.cy.js`

**Tempo:** 2h

#### 6.4 - Testes de Segurança
- ROLE_CAIXA pode visualizar
- ROLE_CAIXA não pode editar
- ROLE_ADMIN pode reordenar
- Sequência integridade em concorrência

**Tempo:** 1.5h

---

## 📅 Timeline de Execução

| Fase | Semana | Dias | Horas | Marcos |
|------|--------|------|-------|--------|
| 1. Fundação | 1 | 2-3 | 4h | Role + Campo + Voter |
| 2. Backend APIs | 1-2 | 3-4 | 5h | Endpoints funcionando |
| 3. Validação | 2 | 1-2 | 2.5h | Integridade garantida |
| 4. Frontend | 2-3 | 3-4 | 5.5h | Interface pronta |
| 5. Auditoria | 3 | 1-2 | 2.5h | Histórico rastreável |
| 6. Testes | 3-4 | 3-4 | 10h | Cobertura 90%+ |
| **TOTAL** | **4** | **14** | **29h** | **Completo** |

---

## 🎯 Objetivos por Fase

### ✓ Fase 1: Fundação
- [ ] Role CAIXA criado
- [ ] Campo `ordem_prioridade` persistindo
- [ ] Voter bloqueando acesso corretamente
- [ ] Tests passando

### ✓ Fase 2: Backend APIs
- [ ] GET `/api/proposals/ordered` funcionando
- [ ] POST `/api/proposals/reorder` funcionando
- [ ] Service layer pronto
- [ ] Tests passando

### ✓ Fase 3: Validação
- [ ] Validator de sequência implementado
- [ ] Constraints aplicadas
- [ ] Edge cases cobertos (vazio, um item, duplicidade)
- [ ] Tests passando

### ✓ Fase 4: Frontend
- [ ] Template de lista ordenável pronta
- [ ] Drag-and-drop funcionando
- [ ] Feedback visual implementado
- [ ] Tests Cypress passando

### ✓ Fase 5: Auditoria
- [ ] Histórico rastreável
- [ ] Timeline em MongoDB pronto
- [ ] Endpoint de histórico pronto
- [ ] Tests passando

### ✓ Fase 6: QA
- [ ] Todos os testes passando
- [ ] Cobertura >= 90%
- [ ] Segurança validada
- [ ] Pronto para produção

---

## 📦 Arquivos a Criar

```
src/
  Enum/
    ✏️ UserRolesEnum.php (modificar - adicionar ROLE_CAIXA)
  
  Regmel/
    Service/
      ✨ ProposalOrderingService.php (novo)
      ✏️ ProposalEvaluationService.php (modificar)
    
    Controller/
      Web/Admin/
        ✨ ProposalOrderingController.php (novo)
  
  Security/
    Voter/
      ✨ ProposalViewer.php (novo)
  
  Validator/
    Constraint/
      ✨ UniqueProposalOrder.php (novo)
  
  EventListener/
    Regmel/
      ✨ ProposalOrderingEventListener.php (novo)
  
  Document/
    ✨ ProposalOrderingTimeline.php (novo)

templates/
  regmel/
    admin/
      proposal/
        ✨ list-ordered.html.twig (novo)

assets/
  js/
    regmel/
      ✨ proposal-ordering.js (novo)

config/
  routes/
    ✏️ admin.yaml (adicionar rota)

tests/
  Unit/
    Regmel/
      Service/
        ✨ ProposalOrderingServiceTest.php
      Validator/
        ✨ UniqueProposalOrderConstraintTest.php
  
  Functional/
    Regmel/
      ✨ ProposalOrderingApiTest.php
      ✨ ProposalOrderingWebTest.php

cypress/
  regmel/
    e2e/
      admin/
        proposal/
          ✨ ordering.cy.js
```

---

## 🚨 Riscos & Mitigação

| Risco | Probabilidade | Impacto | Mitigação |
|-------|---------------|--------|-----------|
| Quebra de sequência em concorrência | Alta | Alto | Usar transação DB + mutex |
| Performance em listas grandes | Média | Médio | Índice em `extra_fields.ordem_prioridade` |
| Erro ao editar ordem (rollback) | Média | Médio | Testes de transação + retry logic |
| Usuários Caixa veem dados indevidos | Baixa | Alto | Voter + testes de segurança rigorosos |
| Arrastrar item não atualiza persistência | Média | Médio | Tests E2E + validação resposta API |

---

## ✅ Checklist Final

- [ ] Todos os requisitos implementados
- [ ] Cobertura de testes >= 90%
- [ ] Todos os testes passando (Unit + Functional + E2E)
- [ ] Segurança validada (ROLE_CAIXA, voter)
- [ ] Performance validada (N+1 queries, índices)
- [ ] Documentação atualizada
- [ ] Code review aprovado
- [ ] Merge em `main`
- [ ] Deploy em staging
- [ ] Testes em staging OK
- [ ] Pronto para produção

---

## 📞 Contato & Suporte

- **Dúvidas técnicas:** Verificar `EVALUATION-PHASE-ANALYSIS.md`
- **Status de progress:** Atualizar este arquivo semanalmente
- **Issues bloqueantes:** Criar issue no GitHub com tag `blocker`

---

**Última atualização:** 23 de março de 2026  
**Próxima revisão:** Após Fase 1
