# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Aurora is a Symfony 7 application (formerly MCM/Mapas Culturais) that manages cultural spaces, events, opportunities, and agents. The project uses a layered, API-first architecture with both web and API controllers serving data to browsers and HTTP clients.

**Tech Stack:**
- PHP 8.4 with Symfony 7.2
- PostgreSQL 16 (relational data)
- MongoDB 7 (audit logs/timelines via Doctrine ODM)
- FrankenPHP (production runtime)
- Bootstrap 5.3 (forked as Aurora UI)
- Kubernetes deployment with Skaffold & Helm

## Quick Reference

**First-time setup:**
```bash
# Copy environment file
cp .env.example .env

# Start Kubernetes cluster with Skaffold
make up

# In another terminal, access the PHP pod
make shell

# Inside pod: run migrations and load fixtures
php bin/console doctrine:migrations:migrate -n
php bin/console app:mongo:migrations:execute
php bin/console doctrine:fixtures:load -n
php bin/console lexik:jwt:generate-keypair
```

**Daily development:**
```bash
make up              # Start dev environment
make shell           # Access PHP pod
make migrate         # Run migrations
make tests_back      # Run tests
make style           # Check code style
make down            # Stop environment
```

**Running commands in pod:**
All `php bin/console` commands must run inside the Kubernetes pod. Use `make shell` to access it, or prefix commands with the Makefile (e.g., `make migrate`).

## Continuous Integration

The project uses GitHub Actions for automated testing and deployment validation.

### PHP Tests and Linting (`.github/workflows/php-tests.yml`)

Runs on: Push to `main`, `feat/*`, `fix/*` branches and all PRs to `main`

**Lint Job:**
- PHP-CS-Fixer (dry-run mode)
- PHP_CodeSniffer (PSR-12 compliance)

**Test Job:**
- Sets up PHP 8.4 with required extensions
- Starts PostgreSQL 16 and MongoDB 7 services
- Runs PHPUnit test suite
- Generates code coverage (uploaded to Codecov)

**Local equivalent:**
```bash
make style        # Run linting
make tests_back   # Run tests
```

### Kubernetes Deployment Test (`.github/workflows/k8s-deploy.yml`)

Runs on: Push to `main`, `feat/k8s-*` branches and all PRs to `main`

**What it tests:**
- Creates a kind (Kubernetes in Docker) cluster
- Installs Helm and adds required chart repositories
- Builds the Docker image and deploys with Skaffold
- Waits for all pods to be ready (PHP, PostgreSQL, MongoDB)
- Runs database migrations (ORM + ODM)
- Tests application health endpoint
- Runs smoke tests (console commands)

**This validates:**
- Helm chart is valid and can deploy successfully
- All Kubernetes resources are properly configured
- Application can start and connect to databases
- Migrations run successfully in Kubernetes environment
- Basic application functionality works

## Development Environment

### Kubernetes with Skaffold & Helm

The project uses **Skaffold** for local development and **Helm** for Kubernetes deployment. All infrastructure is managed through Kubernetes.

#### Skaffold Configuration

Skaffold (`skaffold.yaml`) handles:
- Building the FrankenPHP Docker image (target: `frankenphp_prod`)
- Deploying the Helm chart to Kubernetes
- Port forwarding for local access
- Live reloading during development

```bash
# Start dev environment (builds image, deploys to k8s, port-forwards)
make up            # equivalent to: skaffold dev --port-forward

# Deploy to cluster without watching
make deploy        # equivalent to: skaffold run

# Stop and cleanup resources
make down          # equivalent to: skaffold delete
```

#### Helm Chart Structure

The Helm chart is located in `helm/pvr/` and includes:

**Dependencies** (from `Chart.yaml`):
- **PostgreSQL** (groundhog2k/postgres v1.6.0) - Main relational database
- **MongoDB** (groundhog2k/mongodb v0.7.6) - Timeline/audit data
- **Redis** (groundhog2k/redis v2.2.0) - Caching (optional)

**Templates** (`helm/pvr/templates/`):
- `deployment.yaml` - PHP application deployment (FrankenPHP)
- `service.yaml` - ClusterIP service (overridden to NodePort in dev)
- `ingress.yaml` - Ingress configuration (disabled by default)
- `configmap.yaml` - Environment configuration
- `secrets.yaml` - Sensitive credentials
- `hpa.yaml` - Horizontal Pod Autoscaler (disabled by default)
- `serviceaccount.yaml` - K8s service account

**Values Files**:
- `helm/pvr/values.yaml` - Default production values
- `skaffold-values.yaml` - Development overrides (e.g., `service.type: NodePort`)

#### Working with Kubernetes

The Makefile provides shortcuts for interacting with the Kubernetes cluster:

```bash
# Access PHP pod shell
make shell         # Runs: kubectl exec -it <PHP_POD> -- bash

# Run migrations
make migrate       # Both ORM and ODM
make migrate-orm   # PostgreSQL migrations
make migrate-odm   # MongoDB migrations

# Load test data
make fixtures

# Clear application cache
make reset

# Check code style
make style

# Create admin user
make create-admin-user
```

**Note**: The Makefile automatically discovers the PHP pod using:
```bash
kubectl get pods -l app.kubernetes.io/name=pvr,app.kubernetes.io/part-of=pvr
```

#### Image Build

The `Dockerfile` defines a multi-stage build:
- `frankenphp_base` - Base FrankenPHP image with PHP extensions
- `frankenphp_dev` - Development image with Xdebug
- `frankenphp_prod` - Production image (used by Skaffold)

Skaffold builds the production image and injects it into the Helm chart via:
```yaml
php.image.repository: "{{.IMAGE_REPO_pvr_php}}"
php.image.tag: "{{.IMAGE_TAG_pvr_php}}@{{.IMAGE_DIGEST_pvr_php}}"
```

#### Customizing Helm Values

To customize the deployment, modify `skaffold-values.yaml` (for dev) or `helm/pvr/values.yaml` (for defaults):

```yaml
# Example: Change service type, adjust database credentials, etc.
service:
  type: NodePort  # or LoadBalancer, ClusterIP

postgres:
  enabled: true
  settings:
    superuserPassword:
      value: "your-password"
  userDatabase:
    name:
      value: api
    user:
      value: example
    password:
      value: "your-db-password"

mongodb:
  enabled: true
  settings:
    rootPassword: "your-mongo-password"

php:
  appEnv: dev  # or prod
  appDebug: "1"  # "0" for production
```

#### Accessing the Application

When using `make up` or `skaffold dev --port-forward`:
- The application will be accessible at `http://localhost:8080` (if port-forwarding is configured)
- Skaffold automatically handles port forwarding based on service configuration

To manually port-forward:
```bash
kubectl port-forward svc/pvr 8080:80
```

#### Deploying Helm Chart Independently

If you want to work with Helm directly without Skaffold:

```bash
# Install dependencies
helm dependency update helm/pvr

# Install/upgrade the release
helm upgrade --install pvr helm/pvr -f skaffold-values.yaml

# Uninstall
helm uninstall pvr
```

## Architecture

```
Browser/HttpClient
       ↓
    Routes (config/routes/)
       ↓
   Controller (Web or Api)
       ↓
    Service
       ↓
   Validator → (if invalid) → Exceptions/Violations
       ↓
   Repository
       ↓
   Database (PostgreSQL + MongoDB)
```

### Key Layers:
- **Controllers**: Located in `src/Controller/Api/` (JSON responses) and `src/Controller/Web/` (Twig HTML). Admin controllers are in `src/Controller/Web/Admin/`.
- **Services**: Business logic layer (injected via Symfony DI)
- **Repositories**: Extend `AbstractRepository` and define the Entity class in constructor
- **DTOs**: Data Transfer Objects in `src/DTO/`
- **Entities**: Doctrine ORM entities (PostgreSQL)
- **Documents**: Doctrine ODM documents (MongoDB) for timeline/audit data

### Routing
Routes are organized by controller type in `config/routes/`:
- `api/` - API endpoint routes
- `web/` - Public web routes
- `admin/` - Admin panel routes

Each controller typically has its own YAML route file.

### Database Strategy
- **PostgreSQL**: Main application data (users, agents, spaces, events, opportunities, etc.)
- **MongoDB**: Audit timelines and logs for all entities (high-volume, time-series data)

## Common Commands

### Testing
```bash
# Run all backend tests with fixtures
make tests_back

# Run specific test file without fixtures
make tests_back filename=tests/Functional/MyTest.php fixtures=no

# Run tests with coverage report (outputs to coverage-html/)
make tests_back_coverage

# Run frontend tests (Cypress)
make tests_front
```

Inside a pod/container:
```bash
php bin/phpunit tests/              # Run all tests
php bin/phpunit tests/Functional/   # Run functional tests only
```

### Migrations

**PostgreSQL (ORM):**
```bash
# Generate migration from entity changes
php bin/console doctrine:migrations:diff

# Run pending migrations
php bin/console doctrine:migrations:migrate
```

**MongoDB (ODM):**
```bash
# Generate new ODM migration (creates timestamped file in migrations-odm/)
php bin/console app:mongo:migrations:generate

# Execute ODM migrations
php bin/console app:mongo:migrations:execute
```

MongoDB migrations use a custom implementation since Doctrine ODM doesn't provide built-in migrations. Create classes in `migrations-odm/` with `up()` and `down()` methods.

### Code Quality
```bash
# Auto-fix code style and check with PHPCS
make style

# Or manually:
php bin/console app:code-style  # PHP-CS-Fixer
php vendor/bin/phpcs            # CodeSniffer
```

Code style is enforced via PHP-CS-Fixer and PHP_CodeSniffer (see `.php-cs-fixer.dist.php` and `phpcs.xml.dist`).

### Fixtures
```bash
# Load test data into PostgreSQL
php bin/console doctrine:fixtures:load -n

# Load test data into MongoDB
php bin/console doctrine:mongodb:fixtures:load -n
```

DataFixtures are in `src/DataFixtures/Entity/` (ORM) and `src/DataFixtures/Document/` (ODM).

### Other Useful Commands
```bash
# Clear cache
php bin/console cache:clear

# Debug routes
php bin/console debug:router

# Generate JWT keys
php bin/console lexik:jwt:generate-keypair

# Generate MongoDB proxies
php bin/console doctrine:mongodb:generate:proxies

# Frontend assets
php bin/console importmap:install
php bin/console asset-map:compile

# Execute raw SQL
php bin/console doctrine:query:sql "SELECT * FROM users LIMIT 5"
```

## Creating New Components

### Controllers
1. Create class in `src/Controller/Api/` or `src/Controller/Web/`
2. Extend `AbstractApiController` (returns JSON) or `AbstractWebController` (renders Twig)
3. Define route in corresponding YAML file in `config/routes/`

### Repositories
1. Create class in `src/Repository/` extending `AbstractRepository`
2. Define Entity class in `__construct()` by calling `parent::__construct($registry, MyEntity::class)`

### Commands
Create in `src/Command/` extending Symfony's `Command` class, set `$defaultName` property.

### DataFixtures
Create in `src/DataFixtures/Entity/` (ORM) or `src/DataFixtures/Document/` (ODM), implement `load()` method.

## Technical Decisions

### File Uploads
File uploads use dedicated POST endpoints with `multipart/form-data`:
```
POST /spaces/{id}/images
Content-Type: multipart/form-data
```

Flow: Controller → Service → FileService → Storage

### Enums
Enums use native PHP 8.1+ enums and are kept application-side (not in database). See `help/ENUM.md`.

### Event System
Uses Symfony's native event system (EventListeners and EventSubscribers). See Symfony docs for details.

## Important Notes

- **Business rules live in the application layer**, not in the database
- This project prioritizes **flexibility and customization** over opinionated conventions
- **Security**: Be mindful of XSS, CSRF, SQL injection - Symfony provides built-in protections
- The project was originally called "Aurora" - some references to this name remain in documentation
- Test users are defined in fixtures (see README.md for credentials)
- **Kubernetes-first**: All development and production deployments use Kubernetes/Helm/Skaffold

## Troubleshooting

### Kubernetes/Skaffold Issues

**Pod not starting:**
```bash
# Check pod status and events
kubectl get pods
kubectl describe pod <pod-name>
kubectl logs <pod-name>

# Check if PVC (persistent volume claims) are bound
kubectl get pvc
```

**Cannot connect to database:**
```bash
# Check if PostgreSQL/MongoDB pods are running
kubectl get pods | grep -E 'postgres|mongodb'

# Check database service endpoints
kubectl get svc

# Port-forward to database for direct connection
kubectl port-forward svc/pvr-postgres 5432:5432
kubectl port-forward svc/pvr-mongodb 27017:27017
```

**Skaffold build fails:**
```bash
# Clear Skaffold cache
skaffold delete
docker system prune -f

# Rebuild from scratch
skaffold dev --port-forward --force
```

**Makefile cannot find PHP pod:**
```bash
# Manually check pod labels
kubectl get pods -l app.kubernetes.io/name=pvr -o wide

# If labels are different, update the Makefile PHP_POD selector
```

**Helm dependency issues:**
```bash
# Update Helm dependencies
helm dependency update helm/pvr

# List dependencies
helm dependency list helm/pvr
```

**Permission denied errors in pod:**
```bash
# The FrankenPHP image may have permission issues with volumes
# Check pod security context in helm/pvr/values.yaml
# Ensure persistent volumes have correct permissions
```

### Application Issues

**Migrations fail:**
```bash
# Check database connection in pod
make shell
php bin/console doctrine:query:sql "SELECT 1"

# Verify environment variables
env | grep DATABASE
```

**Cache issues:**
```bash
# Clear all caches (run inside pod via make shell)
php bin/console cache:clear
rm -rf var/cache/*
rm -rf var/log/*
```

**Frontend assets not loading:**
```bash
# Recompile frontend assets
php bin/console importmap:install
php bin/console asset-map:compile
```

## Documentation References

Additional detailed documentation is in the `help/` directory:
- `help/STACK.md` - Technology stack rationale
- `help/TECHNICAL-DECISIONS.md` - Backend technical decisions
- `help/COMMANDS.md` - Comprehensive command documentation
- `help/README.md` - Application architecture details
- `help/DEV-FLOW.md` - Development workflow
- `help/ENUM.md` - Enum implementation details
- `help/DIAGRAM.md` - Database schema
- `help/DEPLOY.md` - Deployment guide
