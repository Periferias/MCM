#!/bin/bash

#############################
### Atualizção do MCM     ###
#############################
# 
# 
# # Como utilizar este script:
# 1 - Dentro do diretório do projeto executar $(git pull)
# 2 - Entre no terminal do container php
# 3 - Entre em docker 
# 4 - ./update.sh



#migrate_database
php bin/console doctrine:migrations:migrate -n
php bin/console app:mongo:migrations:execute
php bin/console importmap:install
php bin/console asset-map:compile

#compile_frontend
php bin/console asset-map:compile
