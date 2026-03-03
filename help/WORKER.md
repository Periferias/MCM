# Worker - Symfony Messenger

Este documento descreve o funcionamento, configuração e gerenciamento do Messenger Worker usado para processar tarefas assíncronas em background no projeto PVR.

<details>
<summary>Acesso Rápido</summary>

[Visão Geral](#visão-geral)<br>
[Configuração](#configuração)<br>
[Desenvolvimento Local](#desenvolvimento-local-docker-compose)<br>
[Produção Kubernetes](#produção-kubernetes)<br>
[Boas Práticas](#boas-práticas)<br>
[Troubleshooting](#troubleshooting)<br>
[Stack de Processamento](#stack-de-processamento-assíncrono)<br>

</details>

## Visão Geral

O projeto utiliza o Symfony Messenger Component para processar tarefas assíncronas, como:
- Exportação de mapas poligonais em ZIP
- Exportação de anuências em ZIP
- Geração de relatórios pesados
- Envio de e-mails em massa
- Processamento de arquivos

### Arquitetura

```
┌─────────────────┐
│   Controller    │ → Despacha mensagem
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  Message Bus    │ → Envia para transport
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│ Doctrine Queue  │ → Tabela `messenger_messages`
│   (async)       │    no PostgreSQL
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│     Worker      │ → Consome mensagens
│  (Processo PHP) │    e executa handlers
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  Message Handler│ → Processa tarefa
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  Notificação    │ → Notifica usuário via
│   (MongoDB)     │    navbar (badge)
└─────────────────┘
```

## Configuração

### Transport

O projeto usa **Doctrine Transport** com PostgreSQL:

**Arquivo:** `config/packages/messenger.yaml`

```yaml
framework:
    messenger:
        failure_transport: failed
        
        transports:
            async:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
                options:
                    queue_name: async
                retry_strategy:
                    max_retries: 3
                    multiplier: 2
                    
            failed: 
                dsn: 'doctrine://default?queue_name=failed'
                
        routing:
            'App\Regmel\Message\GenerateMapFilesZipMessage': async
            'App\Regmel\Message\GenerateAgreementsZipMessage': async
```

**Variável de ambiente (.env):**
```bash
MESSENGER_TRANSPORT_DSN=doctrine://default?queue_name=async
```

### Mensagens e Handlers

**Mensagens disponíveis:**
- `App\Regmel\Message\GenerateMapFilesZipMessage` → Exporta mapas poligonais
- `App\Regmel\Message\GenerateAgreementsZipMessage` → Exporta anuências

**Handlers correspondentes:**
- `App\Regmel\MessageHandler\GenerateMapFilesZipMessageHandler`
- `App\Regmel\MessageHandler\GenerateAgreementsZipMessageHandler`

## Desenvolvimento Local (Docker Compose)

### Como Rodar o Worker

**Opção 1: Terminal interativo (para debug)**
```bash
docker compose exec php php bin/console messenger:consume async -vv
```

**Opção 2: Em background (detached)**
```bash
docker compose exec -d php php bin/console messenger:consume async
```

**Opção 3: Com Supervisor (recomendado para desenvolvimento contínuo)**
```bash
# Adicionar ao docker-compose.yml:
services:
  worker:
    build:
      context: .
      target: frankenphp_dev
    command: php bin/console messenger:consume async --time-limit=3600
    depends_on:
      - postgres
      - mongo
    volumes:
      - .:/var/www
    restart: unless-stopped
```

### Comandos Úteis

**Ver mensagens na fila:**
```bash
docker compose exec php php bin/console messenger:stats
```

**Processar apenas 1 mensagem (útil para debug):**
```bash
docker compose exec php php bin/console messenger:consume async --limit=1 -vv
```

**Ver mensagens que falharam:**
```bash
docker compose exec php php bin/console messenger:failed:show
```

**Reprocessar mensagens falhadas:**
```bash
docker compose exec php php bin/console messenger:failed:retry
```

**Parar o worker (se rodando em background):**
```bash
docker compose exec php pkill -f "messenger:consume"
```

**Verificar se worker está rodando:**
```bash
docker compose exec php ps aux | grep messenger
```

## Produção (Kubernetes)

### Deployment do Worker

Em produção, o worker roda como um **Deployment separado** no Kubernetes, garantindo alta disponibilidade e auto-recuperação.

**Arquivo:** `helm/pvr/templates/worker-deployment.yaml`

```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ include "pvr.fullname" . }}-worker
  labels:
    {{- include "pvr.labels" . | nindent 4 }}
    app.kubernetes.io/component: worker
spec:
  replicas: {{ .Values.worker.replicaCount }}
  selector:
    matchLabels:
      {{- include "pvr.selectorLabels" . | nindent 6 }}
      app.kubernetes.io/component: worker
  template:
    metadata:
      labels:
        {{- include "pvr.selectorLabels" . | nindent 8 }}
        app.kubernetes.io/component: worker
    spec:
      containers:
      - name: worker
        image: "{{ .Values.php.image.repository }}:{{ .Values.php.image.tag }}"
        command:
          - php
          - bin/console
          - messenger:consume
          - async
          - --time-limit=3600
          - --memory-limit=512M
          - -vv
        env:
          {{- include "pvr.env" . | nindent 10 }}
        resources:
          {{- toYaml .Values.worker.resources | nindent 10 }}
      restartPolicy: Always
```

**Configuração de recursos (values.yaml):**
```yaml
worker:
  replicaCount: 2  # 2 workers em produção
  resources:
    limits:
      cpu: 500m
      memory: 512Mi
    requests:
      cpu: 250m
      memory: 256Mi
```

### Gerenciamento no Kubernetes

**Ver status dos workers:**
```bash
kubectl get pods -l app.kubernetes.io/component=worker
```

**Logs em tempo real:**
```bash
kubectl logs -f deployment/pvr-worker
```

**Ver logs de um pod específico:**
```bash
kubectl logs pvr-worker-xxxxx-yyyyy -f
```

**Escalar workers (aumentar/diminuir):**
```bash
# Aumentar para 5 réplicas
kubectl scale deployment pvr-worker --replicas=5

# Reduzir para 1 réplica
kubectl scale deployment pvr-worker --replicas=1
```

**Reiniciar workers (nova versão de código):**
```bash
kubectl rollout restart deployment/pvr-worker
```

**Ver eventos de workers:**
```bash
kubectl describe deployment pvr-worker
```

**Acessar pod do worker para debug:**
```bash
# Listar pods
kubectl get pods -l app.kubernetes.io/component=worker

# Acessar shell do pod
kubectl exec -it pvr-worker-xxxxx-yyyyy -- bash

# Dentro do pod, pode executar comandos
php bin/console messenger:stats
php bin/console messenger:failed:show
```

### Monitoramento

**Métricas importantes:**
- Número de mensagens na fila
- Taxa de sucesso/falha
- Tempo médio de processamento
- Uso de CPU/memória dos workers

**Verificar fila no banco (PostgreSQL):**
```bash
# Acessa o pod principal
kubectl exec -it pvr-php-xxxxx-yyyyy -- bash

# Dentro do pod
php bin/console doctrine:query:sql "SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'async'"
```

## Boas Práticas

### 1. Time Limit
Configure `--time-limit` para evitar memory leaks:
```bash
php bin/console messenger:consume async --time-limit=3600  # 1 hora
```

Após 1 hora, o worker para gracefully e o Kubernetes reinicia automaticamente.

### 2. Memory Limit
Previne consumo excessivo de memória:
```bash
php bin/console messenger:consume async --memory-limit=512M
```

### 3. Limit de Mensagens
Processa N mensagens e para (útil para manutenção):
```bash
php bin/console messenger:consume async --limit=100
```

### 4. Logging
Use `-vv` ou `-vvv` para debug detalhado:
```bash
php bin/console messenger:consume async -vv
```

### 5. Auto-Restart
No Kubernetes, o `restartPolicy: Always` garante que workers sejam reiniciados automaticamente em caso de falha.

### 6. Healthchecks
Configure liveness/readiness probes no Kubernetes:

```yaml
livenessProbe:
  exec:
    command:
      - php
      - bin/console
      - messenger:stats
  initialDelaySeconds: 30
  periodSeconds: 60
```

## Troubleshooting

### ❌ Problema: Worker não processa mensagens

**Verificar:**
1. Worker está rodando?
   ```bash
   # Docker Compose
   docker compose exec php ps aux | grep messenger
   
   # Kubernetes
   kubectl get pods -l app.kubernetes.io/component=worker
   ```

2. Mensagens na fila?
   ```bash
   php bin/console messenger:stats
   ```

3. Logs do worker:
   ```bash
   # Docker Compose
   docker compose logs -f php
   
   # Kubernetes
   kubectl logs -f deployment/pvr-worker
   ```

### ❌ Problema: Mensagens ficam em "failed"

**Solução:**
```bash
# Ver mensagens falhadas
php bin/console messenger:failed:show

# Reprocessar todas
php bin/console messenger:failed:retry

# Reprocessar específica
php bin/console messenger:failed:retry <ID>

# Remover mensagem falhada
php bin/console messenger:failed:remove <ID>
```

### ❌ Problema: Worker consome muita memória

**Solução:**
1. Adicionar `--memory-limit`:
   ```bash
   php bin/console messenger:consume async --memory-limit=512M
   ```

2. Reduzir `--time-limit` para reiniciar mais frequentemente:
   ```bash
   php bin/console messenger:consume async --time-limit=1800  # 30 min
   ```

### ❌ Problema: Mensagens não aparecem na notificação

**Verificar:**
1. Handler está criando notificação?
2. MongoDB está acessível?
3. Usuário tem permissão (ROLE_ADMIN ou ROLE_SUPPORT)?

**Debug:**
```bash
# Verificar documentos no MongoDB
php bin/console doctrine:mongodb:query "db.NotificationDocument.find()"
```

## Stack de Processamento Assíncrono

### Fluxo Completo: Exportação de Anuências

1. **Usuário clica em "Download Todos Documentos"**
   - `ProposalAgreementAdminController::downloadAllAgreements()`

2. **Mensagem despachada**
   - `$messageBus->dispatch(new GenerateAgreementsZipMessage($userId))`

3. **Mensagem entra na fila**
   - Tabela `messenger_messages` no PostgreSQL

4. **Worker processa**
   - `GenerateAgreementsZipMessageHandler::__invoke()`
   - Gera ZIP em `storage/regmel/exports/`

5. **Notificação criada**
   - `NotificationDocumentService::create()`
   - Documento salvo no MongoDB

6. **Usuário vê notificação**
   - Badge vermelho na navbar
   - Link de download com expiração

7. **Usuário baixa arquivo**
   - `ExportsAdminController::downloadExport()`
   - Arquivo `.downloaded` criado

8. **Limpeza automática**
   - `CleanOldExportsCommand` (cron diário)
   - Apaga após 30min do download ou 2h

## Referências

- [Symfony Messenger](https://symfony.com/doc/current/messenger.html)
- [Doctrine Transport](https://symfony.com/doc/current/messenger.html#doctrine-transport)
- [Kubernetes Deployments](https://kubernetes.io/docs/concepts/workloads/controllers/deployment/)
- [Helm Values](https://helm.sh/docs/chart_template_guide/values_files/)

## Comandos Rápidos

```bash
# Local (Docker Compose)
docker compose exec php php bin/console messenger:consume async -vv

# Kubernetes (Produção)
kubectl logs -f deployment/pvr-worker
kubectl scale deployment pvr-worker --replicas=3
kubectl exec -it pvr-worker-xxxxx-yyyyy -- php bin/console messenger:stats
```
