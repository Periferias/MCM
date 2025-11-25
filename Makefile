.PHONY: up deploy down shell migrate migrate-orm migrate-odm fixtures

# Get the pod name for the PHP application
PHP_POD = $(shell kubectl get pods -l app.kubernetes.io/name=pvr,app.kubernetes.io/part-of=pvr -o jsonpath="{.items[0].metadata.name}")

up:
	skaffold dev --port-forward

deploy:
	skaffold run

down:
	skaffold delete

shell:
	kubectl exec -it $(PHP_POD) -- bash

migrate: migrate-orm migrate-odm

migrate-orm:
	kubectl exec -it $(PHP_POD) -- php bin/console doctrine:migrations:migrate -n

migrate-odm:
	kubectl exec -it $(PHP_POD) -- php bin/console app:mongo:migrations:execute

fixtures:
	kubectl exec -it $(PHP_POD) -- php bin/console doctrine:fixtures:load -n --purge-exclusions=city --purge-exclusions=state

reset:
	kubectl exec -it $(PHP_POD) -- php bin/console cache:clear

style:
	kubectl exec -it $(PHP_POD) -- php bin/console app:code-style
	kubectl exec -it $(PHP_POD) -- php vendor/bin/phpcs

create-admin-user:
	kubectl exec -it $(PHP_POD) -- php bin/console app:create-admin-user
