# 📋 Manual da Fase de Avaliação, Classificação e Ordenação de Propostas

**Versão:** 1.0  
**Data:** 25 de março de 2026  
**Aplicação:** Periferia Viva Reformas (PVR)  
**Status:** ✅ Completo e em Produção

---

## 📖 Índice

1. [Visão Geral](#visão-geral)
2. [Fluxo de Avaliação](#fluxo-de-avaliação)
3. [Guia do Usuário](#guia-do-usuário)
4. [Funcionalidades Implementadas](#funcionalidades-implementadas)
5. [APIs e Endpoints](#apis-e-endpoints)
6. [Segurança e Permissões](#segurança-e-permissões)
7. [Auditoria e Histórico](#auditoria-e-histórico)
8. [Troubleshooting](#troubleshooting)

---

## Visão Geral

A Fase de Avaliação permite que administradores do PVR:
- ✅ Avaliem propostas recebidas (Selecionada, Classificada, Não Selecionada)
- ✅ Ordenem propostas por prioridade (drag-and-drop)
- ✅ Exportem dados em planilhas com histórico completo
- ✅ Rastreiem todas as mudanças em auditoria MongoDB
- ✅ Permitam que Caixa visualize propostas (somente-leitura)

**Atores:**
- **ROLE_ADMIN**: Avalia, classifica, reordena propostas
- **ROLE_CAIXA**: Visualiza apenas propostas SELECIONADA e CLASSIFICADA (somente-leitura)
- **ROLE_MANAGER**: Pode visualizar relatórios

---

## Fluxo de Avaliação

```
Proposta Recebida
    ↓
[ANUIDA/NAO_ANUIDA] ← Verificação municipal
    ↓
Libera para Avaliação
    ↓
[SELECIONADA/CLASSIFICADA/NAO_SELECIONADA] ← Avaliação Admin
    ↓
[Ordem de Prioridade] ← Reordenação Manual (Drag-and-Drop)
    ↓
Exportação em Planilhas
    ↓
[Auditoria em MongoDB]
```

### Estados da Proposta

| Estado | Descrição | Quem pode ver | Quem pode editar |
|--------|-----------|---------------|-----------------|
| `AGUARDANDO_ANUENCIA` | Aguardando resposta do município | Admin, Manager | Admin |
| `ANUIDA` | Município aprovou | Admin, Manager, Caixa | Admin |
| `NAO_ANUIDA` | Município reprovou | Admin, Manager | Admin |
| `AGUARDANDO_AVALIACAO_SELECAO` | Pronta para avaliação | Admin | Admin |
| `SELECIONADA` | Aprovada para execução | Admin, Caixa | Admin |
| `CLASSIFICADA` | Aprovada como suplente | Admin, Caixa | Admin |
| `NAO_SELECIONADA` | Rejeitada | Admin, Manager | Admin |

---

## Guia do Usuário

### 1. Acessar Painel de Propostas

```
http://localhost:8080/painel/admin/propostas
```

**Abas disponíveis:**
- 📋 **Todas as Propostas** (22 colunas)
- ✅ **Selecionadas** (23 colunas + input de posição)
- 📁 **Classificadas** (23 colunas + input de posição, ordenadas por ranking)
- ❌ **Não Selecionadas** (22 colunas)

### 2. Avaliar Proposta

1. Clique em **"Avaliar Propostas Anuídas"** (botão verde)
2. Selecione a proposta a avaliar
3. Preencha os campos:
   - **Status**: Selecionada / Classificada / Não Selecionada
   - **Motivo** *(obrigatório)*: Razão da decisão
   - **Notas** *(opcional)*: Observações adicionais
4. Clique em **"Salvar"**

**Dados registrados:**
- `evaluation_result`: Status final
- `evaluation_reason`: Motivo (obrigatório)
- `evaluation_notes`: Notas (opcional)
- `evaluation_completed_at`: Data/hora da avaliação
- `evaluation_completed_by_name`: Nome de quem avaliou

### 3. Reordenar Propostas (Drag-and-Drop)

#### Método 1: Arrastar e Soltar
1. Abra a aba **"Selecionadas"** ou **"Classificadas"**
2. **Clique e arraste** uma linha para nova posição
3. A reordenação é **automática** (não precisa clicar em salvar)

#### Método 2: Editar Posição Manualmente
1. Abra a coluna **"↕ Posição"** na aba
2. Digite a **nova posição** (número)
3. Pressione **Tab** ou **Enter**
4. Aguarde **1 segundo** (auto-save ativado)

**Validações automáticas:**
- ✅ Sequência deve ser contígua (1, 2, 3, ...)
- ✅ Sem duplicidade de ordem
- ✅ Sem quebras na sequência
- ✅ Somente ADMIN pode reordenar (CAIXA é bloqueado)

### 4. Exportar Dados

#### CSV (Planilha Completa)
1. Clique em **"Exportar CSV"** (botão cinza)
2. Arquivo `propostas_[data].csv` será baixado
3. **Incluye campos:**
   - Posição, ID, Região, Estado, Município, Código Município
   - Quantidade casas, Área, Valor total
   - Nome empresa, CNPJ, Tipo Instituição
   - Dados representante: Nome, CPF, Email, Telefone
   - Data de cadastro

#### Mapa Poligonal (KML)
1. Clique em **"Exportar Mapa"** (botão verde)
2. Arquivo `mapa_poligonal.zip` será baixado
3. Importar no Google Earth ou QGIS

#### Arquivo Projeto
1. Clique em **"Exportar Projeto"** (botão azul)
2. Arquivo `projeto.zip` será baixado

---

## Funcionalidades Implementadas

### ✅ Fase 1: Fundação
- Role `ROLE_CAIXA` com permissões específicas
- Campo `ordem_prioridade` para SELECIONADA
- Campo `evaluation_ranking` para CLASSIFICADA
- Voter `ProposalViewer` para controle de acesso

### ✅ Fase 2: Backend APIs
- `GET /api/proposals/ordered?status=SELECIONADA` - Listar propostas ordenadas
- `POST /api/proposals/reorder` - Reordenar propostas
- `ProposalOrderingService` - Lógica de negócio

### ✅ Fase 3: Validação
- ✅ Sequência contígua (1, 2, 3, ...)
- ✅ Sem duplicidade
- ✅ Sem quebras na sequência
- ✅ Validação transacional

### ✅ Fase 4: Frontend
- Drag-and-drop integrado ao template
- Inputs editáveis para posição manual
- Auto-save em 1 segundo com debounce
- Integração em `/painel/admin/propostas`

### ✅ Fase 5: Auditoria
- Document MongoDB `ProposalOrderingTimeline`
- EventListener registra todas as reordenações
- Endpoint `GET /api/proposals/{id}/ordering-history`
- Rastreamento: usuário, data, mudanças realizadas

---

## APIs e Endpoints

### 1. Listar Propostas Ordenadas

```bash
GET /api/proposals/ordered
GET /api/proposals/ordered?status=SELECIONADA
GET /api/proposals/ordered?status=CLASSIFICADA&state=SP&region=Região1
```

**Parâmetros:**
- `status` (opcional): `SELECIONADA` ou `CLASSIFICADA`
- `state` (opcional): Filtrar por estado
- `region` (opcional): Filtrar por região

**Resposta (200 OK):**
```json
[
  {
    "id": "uuid-xxx",
    "name": "Projeto X",
    "company": "Empresa Y",
    "municipality": "São Paulo-SP",
    "region": "Centro",
    "state": "SP",
    "status": "SELECIONADA",
    "order": 1,
    "quantity_houses": 50,
    "evaluation_ranking": null,
    "evaluation_reason": "Melhor pontuação técnica"
  }
]
```

### 2. Reordenar Propostas

```bash
POST /api/proposals/reorder
Content-Type: application/json

{
  "reordering": [
    { "proposalId": "uuid-1", "newOrder": 3 },
    { "proposalId": "uuid-2", "newOrder": 1 },
    { "proposalId": "uuid-3", "newOrder": 2 }
  ]
}
```

**Resposta (200 OK):**
```json
{
  "success": true,
  "message": "Propostas reordenadas com sucesso."
}
```

**Respostas de erro:**
- `400 Bad Request`: Campo inválido ou sequência quebrada
- `404 Not Found`: Proposta não existe
- `403 Forbidden`: Usuário não é ADMIN
- `500 Internal Server Error`: Erro ao atualizar banco

### 3. Histórico de Reordenação

```bash
GET /api/proposals/{proposalId}/ordering-history
```

**Resposta (200 OK):**
```json
{
  "success": true,
  "data": [
    {
      "id": "mongo-id",
      "timestamp": "2026-03-25 14:30:45",
      "userName": "João Silva",
      "changes": [
        {
          "proposalId": "uuid-1",
          "from": 2,
          "to": 1
        }
      ]
    }
  ],
  "total": 1
}
```

---

## Segurança e Permissões

### Control de Acesso

| Ação | ROLE_ADMIN | ROLE_CAIXA | ROLE_MANAGER |
|------|-----------|-----------|-------------|
| Ver todas abas | ✅ | ✅* | ✅ |
| Ver SELECIONADA | ✅ | ✅ | ✅ |
| Ver CLASSIFICADA | ✅ | ✅ | ✅ |
| Ver NAO_SELECIONADA | ✅ | ❌ | ✅ |
| Avaliar propostas | ✅ | ❌ | ❌ |
| Reordenar | ✅ | ❌ | ❌ |
| Editar dados | ✅ | ❌ | ❌ |

*ROLE_CAIXA vê apenas SELECIONADA e CLASSIFICADA (somente-leitura)

### Implementação

```php
// Voter: ProposalViewer.php
#[IsGranted('ROLE_ADMIN')] // Apenas admin pode reordenar
public function reorderProposals(Request $request): JsonResponse { ... }

// Twig: Verificação de acesso
{% if is_granted('ROLE_CAIXA') %}
  <!-- Oculta aba NAO_SELECIONADA -->
{% endif %}
```

---

## Auditoria e Histórico

### Dados Registrados em MongoDB

**Collection:** `proposal_ordering_timeline`

**Campos:**
```json
{
  "_id": ObjectId("..."),
  "proposalId": "uuid-xxx",
  "userId": "uuid-user",
  "userName": "João Silva",
  "timestamp": ISODate("2026-03-25T14:30:45Z"),
  "device": "desktop",
  "platform": "Linux",
  "previousOrdering": [
    { "proposalId": "uuid-1", "order": 1, "name": "Projeto A" },
    { "proposalId": "uuid-2", "order": 2, "name": "Projeto B" }
  ],
  "newOrdering": [
    { "proposalId": "uuid-1", "order": 2, "name": "Projeto A" },
    { "proposalId": "uuid-2", "order": 1, "name": "Projeto B" }
  ],
  "changes": [
    { "proposalId": "uuid-1", "from": 1, "to": 2 },
    { "proposalId": "uuid-2", "from": 2, "to": 1 }
  ]
}
```

### Consultando Auditoria

```bash
# Via API
GET /api/proposals/{proposalId}/ordering-history

# Via MongoDB CLI
mongo regmel_mongodb
db.proposal_ordering_timeline.find({ proposalId: "uuid-xxx" }).sort({ timestamp: -1 })
```

---

## Troubleshooting

### ❌ Posição não salva / erro 500

**Causa:** Cache desatualizado

**Solução:**
```bash
docker compose exec -T php php bin/console cache:clear
```

### ❌ ROLE_CAIXA vendo mais propostas que deveria

**Causa:** Permissões do Voter não aplicadas

**Verificar:**
```bash
docker compose exec -T php php bin/console debug:event-listener
```

### ❌ Histórico não sendo registrado

**Causa:** EventListener não disparando

**Verificar:**
```bash
docker compose logs -f php | grep ProposalOrderingEvent
```

### ❌ Sequência quebrada (ex: 1, 3, 5)

**Validação:** Ocorre automaticamente no endpoint

**Corrigir:** POST `/api/proposals/reorder` com sequência corrigida (1, 2, 3)

### ❌ Importação de dados teste

```bash
docker compose exec -T php php bin/console doctrine:fixtures:load
docker compose exec -T php php bin/console app:mongo:migrations:execute
```

---

## Estrutura de Arquivos

```
src/
├── Controller/
│   └── Api/
│       └── EvaluationApiController.php ← Endpoints
├── Regmel/
│   └── Service/
│       ├── ProposalOrderingService.php ← Lógica de reordenação
│       └── ProposalEvaluationService.php ← Lógica de avaliação
├── Document/
│   └── ProposalOrderingTimeline.php ← MongoDB
├── Event/
│   └── Regmel/
│       └── ProposalOrderingEvent.php ← Evento disparado
├── EventListener/
│   └── Regmel/
│       └── ProposalOrderingEventListener.php ← Auditoria
└── Security/
    └── Voter/
        └── ProposalViewer.php ← Controle de acesso

config/
├── routes/
│   └── api/
│       └── evaluation.yaml ← Rotas da API
└── services.yaml ← Serviços registrados

templates/
└── regmel/
    └── admin/
        └── proposal/
            └── list.html.twig ← Interface drag-and-drop

tests/
├── Unit/
│   └── Regmel/
│       └── Service/
│           └── ProposalOrderingServiceTest.php
├── Functional/
│   └── Api/
│       └── ProposalOrderingApiTest.php
└── E2E/
    └── cypress/
        └── regmel/
            └── admin/
                └── proposal/
                    └── ordering.cy.js
```

---

## Tecnologias Utilizadas

- **Backend:** PHP 8.4, Symfony 7.2
- **ORM:** Doctrine 2 (PostgreSQL)
- **ODM:** Doctrine MongoDB ODM
- **Frontend:** Twig, Bootstrap 5, HTML5 Drag API
- **APIs:** REST JSON, EventDispatcher
- **Testes:** PHPUnit, Cypress
- **CI/CD:** GitHub Actions

---

## Commits Principais

```bash
feat: adicionar dados de representante nas planilhas exportadas e estilizar botões de download
feat: suportar ambas city_code e cityCode para compatibilidade com dados legados
docs: encerrar FASE 4 - Frontend Interface com Drag-and-Drop completa
feat: implementar auditoria de reordenação com Document MongoDB, EventListener e endpoint de histórico
```

---

## Suporte e Próximos Passos

### ✅ Implementado
- Avaliação e classificação de propostas
- Reordenação com drag-and-drop
- Validação de integridade
- Auditoria completa
- Exportação em CSV/KML
- Controle de acesso por role

### ⏳ Futuro (Backlog)
- Testes E2E automatizados com Cypress
- Dashboard com gráficos de estatísticas
- Notificações de mudanças
- Integração com sistema de assinatura digital
- Relatórios avançados

---

## Contato e Documentação

**Documentação técnica:** [help/README.md](help/README.md)  
**Planejamento:** [IMPLEMENTATION-PLAN-REQUIREMENTS.md](IMPLEMENTATION-PLAN-REQUIREMENTS.md)  
**Code Style:** `.php-cs-fixer.dist.php`  
**Environment:** `.env.example`

---

**Versão 1.0** | Março/2026 | Periferia Viva Reformas
