# Export ZIP via CLI — Guia de Operação em Produção (Kubernetes)

Este guia descreve como gerar arquivos ZIP de **poligonais** e **anuências** diretamente no pod em produção, contornando o timeout do navegador.

---

## Pré-requisitos

- Acesso ao cluster Kubernetes (kubectl configurado)
- Permissão para executar comandos no pod PHP

---

## 1. Identificar o pod PHP

```bash
kubectl get pods -l app.kubernetes.io/name=pvr,app.kubernetes.io/part-of=pvr
```

Anote o nome do pod (ex: `pvr-php-7d9f8b6c4-xk2lp`).

---

## 2. Executar o comando de export

### Exportar todas as poligonais

```bash
kubectl exec -it <pod-name> -- php bin/console app:regmel:export-zip poligonais
```

### Exportar poligonais de propostas selecionadas

```bash
kubectl exec -it <pod-name> -- php bin/console app:regmel:export-zip poligonais --status="Selecionada"
```

### Exportar todas as anuências

```bash
kubectl exec -it <pod-name> -- php bin/console app:regmel:export-zip anuencias
```

### Exportar anuências aprovadas

```bash
kubectl exec -it <pod-name> -- php bin/console app:regmel:export-zip anuencias --status="Anuída pelo Município"
```

### Exportar anuências aguardando validação

```bash
kubectl exec -it <pod-name> -- php bin/console app:regmel:export-zip anuencias --status="Aguardando Validação da Anuência"
```

### Definir caminho de saída customizado

```bash
kubectl exec -it <pod-name> -- php bin/console app:regmel:export-zip poligonais --output=/tmp/poligonais.zip
```

---

## 3. Valores aceitos para `--status`

| Valor |
|---|
| `Enviada` |
| `Recebida` |
| `Sem Adesão do Município` |
| `Aguardando Validação da Anuência` |
| `Anuída pelo Município` |
| `Não Anuída pelo Município` |
| `Selecionada` |
| `Não Selecionada` |
| `Classificada` |

Se `--status` for omitido, **todas as propostas** são incluídas.

---

## 4. Localizar o arquivo gerado

Por padrão, o ZIP é salvo dentro do pod em:

```
{project_dir}/storage/regmel/exports/{tipo}_{datetime}.zip
```

Exemplo: `/var/www/storage/regmel/exports/anuencias_2026-03-13_10-30-00.zip`

O caminho exato é exibido no terminal ao final da execução:

```
ZIP gerado em: /var/www/storage/regmel/exports/anuencias_2026-03-13_10-30-00.zip
```

---

## 5. Baixar o ZIP para sua máquina local

Use `kubectl cp` para copiar o arquivo do pod para o seu computador:

```bash
kubectl cp <pod-name>:/var/www/storage/regmel/exports/anuencias_2026-03-13_10-30-00.zip ./anuencias.zip
```

Ajuste o caminho do pod conforme exibido pelo comando no passo anterior.

---

## 6. Via shell interativo (alternativa)

Se preferir executar múltiplos comandos ou monitorar o progresso:

```bash
# Abre shell no pod
kubectl exec -it <pod-name> -- bash

# Dentro do pod:
php bin/console app:regmel:export-zip anuencias --status="Anuída pelo Município"

# Sair do pod
exit
```

---

## Observações

- O comando pode demorar vários minutos dependendo do volume de propostas — isso é esperado e não há timeout.
- Arquivos físicos **não são renomeados** em disco. Os ZIPs de anuência já entregam os arquivos com nomes legíveis (`municipio-estado-empresa-anuencia-v01-uuid8.pdf`) reorganizados internamente.
- Se um arquivo de proposta não for encontrado em disco, o comando emite um aviso `[WARN]` e continua sem abortar.
- Para descobrir o nome exato do pod automaticamente:

```bash
PHP_POD=$(kubectl get pods -l app.kubernetes.io/name=pvr,app.kubernetes.io/part-of=pvr -o jsonpath="{.items[0].metadata.name}")
kubectl exec -it $PHP_POD -- php bin/console app:regmel:export-zip anuencias
kubectl cp $PHP_POD:/var/www/storage/regmel/exports/$(kubectl exec $PHP_POD -- ls -t /var/www/storage/regmel/exports/ | head -1) ./export.zip
```
