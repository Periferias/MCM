# DEPLOY do Projeto Periferia Viva Reformas (PVR)

O projeto PVR possui duas abordagens de deploy:

1. **Deploy tradicional com Docker Compose** (para ambientes simples ou desenvolvimento local)
2. **Deploy em Kubernetes** (recomendado para staging e produção usando AWS EKS/Rancher)

## Deploy em Kubernetes (Recomendado para produção)

O projeto utiliza uma arquitetura GitOps com:

- **GitHub Actions** para CI/CD
- **ECR** (Elastic Container Registry) para armazenamento de imagens
- **Helm** para empacotamento Kubernetes
- **Skaffold** para desenvolvimento local com Kubernetes
- **FluxCD** para GitOps em ambientes staging/produção (configuração externa)

### Fluxo de CI/CD

1. **Push para branch `main` ou `feat/*`** → Dispara workflow `docker-build-push.yml`
2. **Build da imagem** → Target `frankenphp_prod` do Dockerfile
3. **Tagging** → Tags no formato FluxCD: `{branch-sanitized}-{sha}-{timestamp}`
4. **Push para ECR** → Registry: `298680963177.dkr.ecr.us-east-1.amazonaws.com/melhorias-habitacionais`
5. **Assinatura** → Cosign (Sigstore) para verificação de integridade
6. **Deploy automático** → FluxCD detecta nova imagem e atualiza deployment

### Ambientes

- **Staging**: AWS EKS cluster (us-east-1)
- **Produção**: Rancher Kubernetes (infraestrutura Ministério)
- **Local**: Docker Compose ou Kubernetes com Skaffold

### Configuração do Helm Chart

O chart Helm está em `helm/pvr/` e inclui:

- **Dependências**: PostgreSQL 16, MongoDB 7, Redis (opcional)
- **Valores padrão**: `helm/pvr/values.yaml`
- **Override desenvolvimento**: `skaffold-values.yaml`
- **Override staging**: `values-staging.yaml` (externo)
- **Override produção**: `values-production.yaml` (externo)

### Comandos para desenvolvimento com Kubernetes

```bash
# Desenvolvimento local com Skaffold
skaffold dev --port-forward

# Build e deploy único
skaffold run

# Limpar recursos
skaffold delete

# Acessar pod PHP
make shell

# Executar migrações
make migrate
```

---

## Deploy Tradicional com Docker Compose

<details>
<summary>Clonar a aplicação</summary>

### `clone`

Faça o clone da aplicação

```shell
git clone https://github.com/ecossistema-aurora/regmel
```

ou

```shell
git clone git@github.com:ecossistema-aurora/regmel.git
```

### `branch`

O branch da produção deverá ser o `production`

```shell
git checkout production
```

</details>

<details>
<summary>Instalar/Preparar a Aplicação</summary>

### `env`

Copie o arquivo `.env.example`, o novo arquivo terá as configurações de acesso a aplicação, servidor de email, e tipo de ambiente

```shell
cp .env.example .env
```

### `setup`

O primeiro a se fazer em um ambiente de deploy é garantir algumas permissões, para isso basta executar:

```shell
make permissions
```

Precisamos agora criar os bancos de dados, tabelas, dados, instalar dependências e tudo o mais, para isso basta executar:

```shell
make setup
```

### `regmel`

Para a aplicação REGMEL há um comando que cria um conjunto de dados necessários para o processo de cadastro dos Municipios e Empresas, basta executar:

```shell
make demo-regmel
```

Esse comando gerará um usuário padrão administrador para o sistema

<table>
  <tr>
    <th>Email</th>
    <th>Senha</th>
  </tr>
  <tbody>
    <tr>
      <td>admin@regmel.com</td>
      <td>Aurora@2024</td>
    </tr>
  </tbody>
</table>

</details>

<details>
<summary>Pós Instalação (Importante)</summary>

### `env`

Após a instalação precisamos configurar o arquivo `.env`:

- **linha 18:** Alterar para `APP_ENV=prod`
- **linha 55:** Configurar conforme o serviço de email
- **linha 59:** Configurar o endereço de email

</details>

<details>
<summary>Atualização do ambiente (Quando houver novas versões)</summary>

### `pull`

Atualizar o branch

```shell
git pull origin production
```

### `banco de dados`

Atualizar o banco de dados (tabelas)

```shell
make migrate_database
```

### `assets`

Compilar o CSS/Javascript

```shell
make compile_frontend
```

</details>

---

## Comandos disponíveis

Para ver a lista completa de comandos [acesse aqui](./COMMANDS.md)

