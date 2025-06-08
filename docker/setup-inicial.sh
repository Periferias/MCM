#!/bin/bash

#############################
### Setup inicial MCM     ###
#############################
# 
#########################################################################                    
#  NÃO EXECUTAR ESTE SCRIPT APÓS O PRIMEIRO SETUP DA APLICAÇÃO.         #
#  EXECUTÁ-LO NAVAMENTE IRÁ APAGAR TODOS OS REGISTROS DA APLICAÇÃO      #   
#########################################################################
# 
# # Como utilizar este script:
# 1 - Dentro do diretório do projeto executar $(docker compose up -d)
# 2 - Entre no terminal do container php
# 3 - Entre em docker 
# 4 - ./setup.sh
# 5 - Testar os acessos com usuários padrão criados no setup
####        Manager User created   
####        ====================
####        User: admin@snp.email
####        Pass: Aurora@2024
####
####
####        Admin User created
####        ==================
####        User: admin@regmel.com
####        Pass: Aurora@2024
#
# 6 - Pós Instalação (Importante)
#       .env
#           Após a instalação precisamos configurar o arquivo .env:
#           linha 18: Alterar para APP_ENV=prod
#           linha 55: Configurar conforme o serviço de email
#           linha 59: Configurar o endereço de email

cd ../


cp .env.example .env

#make_permission
mkdir -p var/ ; mkdir -p vendor/ ; mkdir -p config/jwt ; chmod -R 775 assets/ config/jwt var/ vendor/ public/

#install_dependencies
composer install --ignore-platform-req=ext-mongodb

#copy_dist
cp phpcs.xml.dist phpcs.xml
cp phpunit.xml.dist phpunit.xml

#reset-deep(without dir remove) 
php bin/console cache:clear
php bin/console doctrine:mongodb:schema:drop --search-index
php bin/console doctrine:mongodb:schema:drop --collection
php bin/console doctrine:mongodb:schema:drop --db
php bin/console doctrine:mongodb:schema:create
php bin/console d:d:d -f
php bin/console d:d:c 


#migrate_database
php bin/console doctrine:migrations:migrate -n
php bin/console app:mongo:migrations:execute
php bin/console importmap:install
php bin/console asset-map:compile


#generate_proxies 
php bin/console doctrine:mongodb:generate:proxies


#load_fixtures
php bin/console doctrine:fixtures:load -n --purge-exclusions=city --purge-exclusions=state


#install_frontend
php bin/console importmap:install

#compile_frontend
php bin/console asset-map:compile

#generate_keys
php bin/console lexik:jwt:generate-keypair --overwrite -n


#reset-deep 
rm -rf var/storage
rm -rf assets/uploads
rm -rf assets/vendor
rm -rf public/assets
rm -rf var/cache
rm -rf var/log
php bin/console cache:clear
php bin/console doctrine:mongodb:schema:drop --search-index
php bin/console doctrine:mongodb:schema:drop --collection
php bin/console doctrine:mongodb:schema:drop --db
php bin/console doctrine:mongodb:schema:create
php bin/console d:d:d -f
php bin/console d:d:c 

#migrate_database
php bin/console doctrine:migrations:migrate -n
php bin/console app:mongo:migrations:execute
php bin/console importmap:install
php bin/console asset-map:compile

#make demo-regmel
php bin/console app:create-admin-user
php bin/console app:demo-regmel
