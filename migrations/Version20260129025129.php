<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260129025129 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add agreement document fields to Initiative entity for proposal agreement flow';
    }

    public function up(Schema $schema): void
    {
        // Não há alterações no schema, pois os campos serão armazenados em extra_fields (JSONB)
        // Esta migration documenta a adição dos seguintes campos no extra_fields:
        // - agreement_status: 'awaiting' | 'submitted' | 'approved' | 'rejected'
        // - agreement_file: string (nome do arquivo PDF)
        // - agreement_updated_at: datetime
        // - agreement_updated_by: user_id (UUID)
        // - agreement_updated_by_name: string
        // - agreement_reason: string (motivo da aprovação/rejeição)
    }

    public function down(Schema $schema): void
    {
        // Não há reversão necessária, pois os campos estão em extra_fields
    }
}
