[![codecov](https://codecov.io/gh/secultce/aurora/graph/badge.svg?token=CG2AH922DO)](https://codecov.io/gh/secultce/aurora)
[![Docker Build and Push](https://github.com/secultce/aurora/actions/workflows/docker-build-push.yml/badge.svg)](https://github.com/secultce/aurora/actions/workflows/docker-build-push.yml)
[![Kubernetes Deployment Test](https://github.com/secultce/aurora/actions/workflows/k8s-deploy.yml/badge.svg)](https://github.com/secultce/aurora/actions/workflows/k8s-deploy.yml)
[![PHP Tests](https://github.com/secultce/aurora/actions/workflows/php-tests.yml/badge.svg)](https://github.com/secultce/aurora/actions/workflows/php-tests.yml)

# Periferia Viva Reformas (PVR)

> Plataforma de Verificação de Requerimentos para o programa Periferia Viva Reformas

> Sistema monolítico Symfony com arquitetura em camadas e abordagem API First. Gerencia cadastro de beneficiários, projetos de reforma, acompanhamento de obras e processos administrativos com suporte a PostgreSQL (dados relacionais) e MongoDB (timelines/auditoria).

### Ajustes e melhorias

O projeto ainda está em desenvolvimento e as próximas atualizações serão voltadas para as seguintes tarefas:

- [x] Suporte a desenvolvimento local com Docker Compose
- [x] Implementação de CI/CD com GitHub Actions
- [x] Deploy em Kubernetes com Skaffold e Helm
- [x] Ambiente de staging na AWS EKS
- [ ] Ambiente de produção no cluster Rancher do Ministério
- [ ] Monitoramento e observabilidade

## 💻 Pré-requisitos

Antes de começar, verifique se você atendeu aos seguintes requisitos:

- Docker e Docker Compose instalados (para desenvolvimento local)
- kubectl e Helm (para ambientes Kubernetes)
- Skaffold (para desenvolvimento com Kubernetes)
- Acesso ao cluster Kubernetes (para staging/produção)
- Credenciais AWS configuradas (para staging)
- Acesso ao repositório ECR da AWS

## 🚀 Instalação e Configuração

### Desenvolvimento Local (Docker Compose)

Para ambiente de desenvolvimento local com Docker Compose:

```bash
# Clone o repositório
git clone git@github.com:periferias/pvr.git
cd pvr

# Copie o arquivo de ambiente
cp .env.example .env  # Configure as variáveis de ambiente conforme necessário

# Inicie os containers
make up

# Execute o setup completo (dependências, migrações, fixtures)
make setup
```

A aplicação estará disponível em `http://localhost:8080`.

#### Comandos úteis para desenvolvimento local

```bash
make shell              # Acessa o container PHP
make migrate            # Executa migrações (ORM + ODM)
make tests_back         # Executa testes de backend
make tests_front        # Executa testes de frontend (Cypress)
make style              # Verifica e corrige estilo de código
make reset              # Limpa cache da aplicação
make demo-regmel        # Carrega dados de demonstração REGMEL
```

### Desenvolvimento com Kubernetes Local (Opcional)

Para desenvolvimento com Kubernetes local (Kind/Minikube), utilize Skaffold com o perfil de desenvolvimento:

```bash
# Instale Kind e Skaffold
brew install kind skaffold  # macOS
# ou use o script do CI

# Crie um cluster Kind
kind create cluster --name pvr

# Configure o contexto kubectl
kubectl cluster-info --context kind-pvr

# Execute o deploy com Skaffold (watch mode)
skaffold dev --port-forward
```

Isso criará um cluster local, construirá a imagem Docker e implantará a aplicação com hot-reload.

### Staging (AWS EKS)

O ambiente de staging utiliza um cluster Kubernetes na AWS com os seguintes componentes:

- **Amazon EKS** (Kubernetes)
- **ECR** (Container Registry)
- **RDS PostgreSQL** (opcional)
- **DocumentDB MongoDB** (opcional)

#### Pipeline de CI/CD

1. **Build de Imagem Docker**: O workflow `docker-build-push.yml` constrói a imagem Docker (target: `frankenphp_prod`) e a publica no ECR com tags seguindo convenções do FluxCD.
2. **Teste de Deploy Kubernetes**: O workflow `k8s-deploy.yml` cria um cluster Kind local, executa deploy com Skaffold/Helm e valida a aplicação.
3. **Testes PHP**: O workflow `php-tests.yml` executa testes unitários e funcionais com cobertura de código.

#### Deploy manual para staging

```bash
# Configure credenciais AWS e contexto kubectl
aws configure
aws eks update-kubeconfig --name staging-cluster --region us-east-1

# Atualize dependências do Helm
helm dependency update helm/pvr

# Deploy com Skaffold
skaffold run --tail=false --wait-for-connection=true

# Ou deploy direto com Helm (usando valores padrão + overrides)
helm upgrade --install pvr helm/pvr -f helm/pvr/values.yaml -f values-staging.yaml
```

#### Configuração para staging

Crie um arquivo `values-staging.yaml` com overrides específicos para o ambiente de staging:

```yaml
php:
  appEnv: staging
  appDebug: "1"
  image:
    repository: 298680963177.dkr.ecr.us-east-1.amazonaws.com/melhorias-habitacionais
    tag: "latest"  # ou use tag específica do ECR

service:
  type: LoadBalancer

ingress:
  enabled: true
  annotations:
    kubernetes.io/ingress.class: alb
    alb.ingress.kubernetes.io/scheme: internet-facing

# Configurações de banco de dados externos (opcional)
postgres:
  enabled: false
  url: "postgresql://${DB_USER}:${DB_PASSWORD}@${DB_HOST}:5432/${DB_NAME}"

mongodb:
  enabled: false
  url: "mongodb://${MONGO_USER}:${MONGO_PASSWORD}@${MONGO_HOST}:27017/${MONGO_DB}"
```

### Produção (Cluster Rancher Kubernetes - Ministério)

O ambiente de produção utiliza um cluster Rancher Kubernetes hospedado na infraestrutura do Ministério.

#### Diferenças em relação ao staging

- **Segurança reforçada**: Network policies, pod security policies, secrets gerenciados externamente
- **Alta disponibilidade**: Múltiplas réplicas, auto-scaling horizontal
- **Monitoramento**: Integração com Prometheus/Grafana do Ministério
- **Backup**: Backup automatizado de bancos de dados
- **DNS interno**: Utilização do DNS interno do Ministério

#### Processo de deploy para produção

```bash
# Acesse o cluster via Rancher
export KUBECONFIG=~/.kube/config-production

# Valide o chart Helm
helm lint helm/pvr

# Dry-run do deploy
helm upgrade --install pvr helm/pvr -f helm/pvr/values.yaml -f values-production.yaml --dry-run

# Deploy real (requer aprovação)
helm upgrade --install pvr helm/pvr -f helm/pvr/values.yaml -f values-production.yaml
```

#### Configuração para produção

Crie um arquivo `values-production.yaml` com overrides específicos para o ambiente de produção:

```yaml
php:
  appEnv: prod
  appDebug: "0"
  image:
    repository: 298680963177.dkr.ecr.us-east-1.amazonaws.com/melhorias-habitacionais
    tag: "latest"  # Em produção, use tags versionadas ou SHAs específicos
  resources:
    requests:
      memory: "256Mi"
      cpu: "250m"
    limits:
      memory: "512Mi"
      cpu: "500m"

replicaCount: 3

autoscaling:
  enabled: true
  minReplicas: 2
  maxReplicas: 10
  targetCPUUtilizationPercentage: 70

ingress:
  enabled: true
  annotations:
    kubernetes.io/ingress.class: nginx
    cert-manager.io/cluster-issuer: letsencrypt-prod
  hosts:
    - host: app.cidades.gov.br
      paths: ["/"]
  tls:
    - hosts:
        - app.cidades.gov.br
      secretName: app-tls

# Use bancos de dados externos gerenciados
postgres:
  enabled: false
  url: "postgresql://${DB_USER}:${DB_PASSWORD}@${DB_HOST}:5432/${DB_NAME}"

mongodb:
  enabled: false
  url: "mongodb://${MONGO_USER}:${MONGO_PASSWORD}@${MONGO_HOST}:27017/${MONGO_DB}"
```

## ☕ Uso da Aplicação

### Acesso via Web

- **Local**: `http://localhost:8080`
- **Staging**: `https://staging.app.cidades.gov.br`
- **Produção**: `https://app.cidades.gov.br`

### API Endpoints

A API segue convenções RESTful e está documentada via OpenAPI. Para acessar a documentação:

```bash
# Localmente após subir a aplicação
open http://localhost:8080/api/docs
```

### Usuários de teste (ambiente local)

<table>
<tr><th>Email</th><th>Senha</th></tr>
<tr><td>mariadebetania@example.com</td><td>Aurora@2024</td></tr>
<tr><td>saracamilo@example.com</td><td>Aurora@2024</td></tr>
<tr><td>paulodetarso@example.com</td><td>Aurora@2024</td></tr>
<tr><td>admin@regmel.com</td><td>Aurora@2024</td></tr>
</table>

## 🔧 Arquitetura Técnica

### Stack Tecnológica

- **PHP 8.4** com Symfony 7.2
- **PostgreSQL 16** (dados relacionais)
- **MongoDB 7** (timelines/auditoria via Doctrine ODM)
- **FrankenPHP** (runtime PHP production)
- **Bootstrap 5.3** (customizado para PVR)
- **Kubernetes** (orquestração de containers)
- **Helm** (gerenciamento de pacotes Kubernetes)
- **Skaffold** (desenvolvimento com Kubernetes)

### Diagrama de Arquitetura

```
Browser/HttpClient
       ↓
    Routes (config/routes/)
       ↓
   Controller (Web ou Api)
       ↓
    Service
       ↓
   Validator → (if invalid) → Exceptions/Violations
       ↓
   Repository
       ↓
   Database (PostgreSQL + MongoDB)
```

### Estrutura de Diretórios

```
src/
├── Controller/     # Controladores Web e API
├── Service/        # Lógica de negócio
├── Repository/     # Camada de acesso a dados
├── Entity/         # Entidades Doctrine ORM
├── Document/       # Documentos Doctrine ODM
├── DTO/           # Data Transfer Objects
├── Enum/          # Enums da aplicação
└── ...

helm/pvr/          # Chart Helm para Kubernetes
├── templates/     # Templates Kubernetes
├── Chart.yaml    # Metadados do chart
└── values.yaml   # Valores padrão
```

## 📫 Contribuindo para o Projeto

Para contribuir com o projeto, siga estas etapas:

1. Bifurque este repositório.
2. Crie um branch: `git checkout -b <nome_branch>`.
3. Faça suas alterações e confirme-as: `git commit -m '<mensagem_commit>'`
4. Envie para o branch original: `git push origin pvr/<local>`
5. Crie a solicitação de pull.

Como alternativa, consulte a documentação do GitHub em [como criar uma solicitação pull](https://help.github.com/en/github/collaborating-with-issues-and-pull-requests/creating-a-pull-request).

### Convenções de Commit

- Use o padrão Conventional Commits
- Relacione issues com `#<número>` no corpo do commit
- Execute `make style` antes de commitar para garantir padrões de código

## 🤝 Colaboradores

Agradecemos às seguintes pessoas que contribuíram para este projeto:

<table>
  <tr>
    <td align="center">
      <a href="https://github.com/vitfera" title="vitfera">
        <img src="https://github.com/vitfera.png" width="100px;" alt="Foto do vitfera no GitHub"/><br>
        <sub>
          <b>vitfera</b>
        </sub>
      </a>
    </td>
    <td align="center">
      <a href="https://github.com/lpirola" title="lpirola">
        <img src="https://github.com/lpirola.png" width="100px;" alt="Foto do lpirola no GitHub"/><br>
        <sub>
          <b>lpirola</b>
        </sub>
      </a>
    </td>
  </tr>
</table>

## 😄 Seja um dos contribuidores

Quer fazer parte desse projeto? Clique [AQUI](CONTRIBUTING.md) e leia como contribuir.

## 📝 Licença

Esse projeto está sob licença. Veja o arquivo [LICENÇA](LICENSE.md) para mais detalhes.

## 🔗 Links Rápidos

- [Stack Tecnológica](./help/STACK.md)
- [Decisões Técnicas de Backend](./help/TECHNICAL-DECISIONS.md)
- [Esquema do Banco de Dados](./help/DIAGRAM.md)
- [Como criar issues](./help/CREATE-ISSUES.md)
- [Como abrir Pull Requests](./help/CREATE-PULL-REQUESTS.md)
- [Fluxo de Desenvolvimento](./help/DEV-FLOW.md)
- [Enums](./help/ENUM.md)
- [Arquitetura da Aplicação](./help/README.md)
- [Comandos do terminal](./help/COMMANDS.md)
- [Deploy](./help/DEPLOY.md)
