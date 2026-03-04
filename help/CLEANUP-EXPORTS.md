# Sistema de Limpeza Automática de Arquivos de Exportação

## Visão Geral

Sistema automatizado para gerenciar arquivos ZIP de exportação de mapas poligonais, evitando acúmulo de espaço em disco.

**Estratégia**: Arquivos expiram em **2 horas** e são deletados automaticamente a cada 2 horas via CronJob.

## Componentes

### 1. Comando de Limpeza

**Arquivo**: `src/Command/CleanupExportFilesCommand.php`

#### Uso Manual

```bash
# Deletar arquivos com mais de 2 horas
php bin/console app:cleanup-export-files

# Deletar arquivos com mais de 6 horas
php bin/console app:cleanup-export-files --max-age=6

# Simular (dry-run) sem deletar
php bin/console app:cleanup-export-files --dry-run

# Modo verboso
php bin/console app:cleanup-export-files -v
```

#### Uso no Kubernetes

```bash
# Executar manualmente no pod
kubectl exec -it <pod-name> -- php bin/console app:cleanup-export-files

# Ver logs do CronJob
kubectl logs -l component=cleanup-job

# Ver CronJobs configurados
kubectl get cronjobs

# Ver histórico de jobs
kubectl get jobs | grep cleanup
```

### 2. CronJob Kubernetes

**Arquivo**: `helm/pvr/templates/cronjob-cleanup.yaml`

- **Frequência**: A cada 2 horas (00:00, 02:00, 04:00, etc.)
- **Idade máxima**: 2 horas
- **Política**: Não permite execuções simultâneas

#### Customizar Frequência

Editar `schedule` no CronJob:

```yaml
# A cada 1 hora
schedule: "0 * * * *"

# A cada 4 horas
schedule: "0 */4 * * *"

# Diariamente às 3h da manhã
schedule: "0 3 * * *"
```

### 3. Notificação de Expiração

**Arquivo**: `assets/js/notifications.js`

Monitora notificações com links de download e:
- Mostra tempo restante antes de expirar
- Desabilita botão quando arquivo expira
- Atualiza automaticamente a cada minuto

### 4. Armazenamento Persistente

**Arquivo**: `helm/pvr/templates/pvc-exports.yaml`

PersistentVolumeClaim compartilhado entre pods para `/var/exports`.

## Fluxo Completo

```
1. Usuário solicita download → ZIP gerado em /var/exports
   ↓
2. Notificação criada com link + timestamp expiração (2h)
   ↓
3. JavaScript monitora expiração na interface
   ↓
4. A cada 2 horas: CronJob executa comando de limpeza
   ↓
5. Arquivos com +2h são deletados automaticamente
```

## Configuração

### Helm Values

Adicionar em `helm/pvr/values.yaml`:

```yaml
exports:
  persistence:
    enabled: true
    size: 5Gi
    accessMode: ReadWriteMany
    # storageClass: fast-ssd (opcional)

cronjob:
  cleanup:
    enabled: true
    schedule: "0 */2 * * *"
    maxAge: 2  # horas
```

### Deploy

```bash
# Com Helm
helm upgrade --install pvr ./helm/pvr -f values-production.yaml

# Com Skaffold
skaffold run
```

## Monitoramento

### Logs do CronJob

```bash
# Últimos logs
kubectl logs -l component=cleanup-job --tail=100

# Seguir logs em tempo real
kubectl logs -l component=cleanup-job -f

# Logs de job específico
kubectl logs job/pvr-cleanup-export-files-28475920
```

### Métricas

O comando gera logs estruturados com:
- Quantidade de arquivos deletados
- Espaço liberado
- Arquivos mantidos
- Erros encontrados

### Alertas

Configurar alertas no Prometheus/Grafana para:
- Jobs com falha
- Uso de disco em `/var/exports`
- Tempo de execução anormal

## Troubleshooting

### CronJob não está executando

```bash
# Verificar se CronJob existe
kubectl get cronjob pvr-cleanup-export-files

# Verificar última execução
kubectl get jobs | grep cleanup

# Ver eventos
kubectl describe cronjob pvr-cleanup-export-files
```

### Arquivos não sendo deletados

```bash
# Executar manualmente com verbose
kubectl exec -it <pod> -- php bin/console app:cleanup-export-files -v

# Verificar permissões do diretório
kubectl exec -it <pod> -- ls -la /app/var/exports

# Verificar espaço em disco
kubectl exec -it <pod> -- df -h /app/var/exports
```

### Volume não está montado

```bash
# Verificar PVC
kubectl get pvc

# Verificar volume no pod
kubectl describe pod <pod-name> | grep -A5 "Volumes:"

# Criar PVC se não existir
kubectl apply -f helm/pvr/templates/pvc-exports.yaml
```

## Testes

### Testar Localmente (Docker Compose)

```bash
# Entrar no container
make shell

# Criar alguns arquivos de teste
touch var/exports/test-old-{1..5}.zip
touch var/exports/test-new.zip

# Ajustar data de modificação (simular arquivos antigos)
find var/exports -name "test-old-*.zip" -exec touch -t 202602010000 {} \;

# Executar limpeza (dry-run)
php bin/console app:cleanup-export-files --dry-run -v

# Executar limpeza real
php bin/console app:cleanup-export-files -v
```

### Testar no Kubernetes

```bash
# Criar Job manual (one-time)
kubectl create job --from=cronjob/pvr-cleanup-export-files manual-cleanup-test

# Acompanhar execução
kubectl logs -f job/manual-cleanup-test

# Limpar job de teste
kubectl delete job manual-cleanup-test
```

## Manutenção

### Ajustar Tempo de Expiração

1. **Backend** (`GenerateMapFilesZipMessageHandler.php`):
   ```php
   $expiresAt = (clone $createdAt)->modify('+4 hours'); // alterar aqui
   ```

2. **CronJob** (`cronjob-cleanup.yaml`):
   ```yaml
   - --max-age=4  # alterar aqui
   ```

3. **Interface** (mensagem no handler):
   ```php
   '(expira em 4h)'  // alterar aqui
   ```

### Aumentar Espaço de Armazenamento

```bash
# Editar PVC
kubectl edit pvc pvr-exports-pvc

# Ou via Helm values
helm upgrade pvr ./helm/pvr --set exports.persistence.size=10Gi
```

## Melhorias Futuras

- [ ] Deletar arquivo imediatamente após download bem-sucedido
- [ ] Alertar usuário quando arquivo está próximo de expirar (push notification)
- [ ] Estatísticas de uso (quantos arquivos gerados/dia, espaço total)
- [ ] Compressão adicional para arquivos grandes
- [ ] Storage S3 para arquivos temporários (evitar uso de disco local)
