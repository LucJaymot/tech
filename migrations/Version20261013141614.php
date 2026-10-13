<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261013141614 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Public slug of soirées, accent colour and logo of collectives';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE collective ADD couleur_accent VARCHAR(7) DEFAULT \'#6366f1\' NOT NULL');
        $this->addSql('ALTER TABLE collective ADD logo VARCHAR(255) DEFAULT NULL');
        // Existing soirées get "soiree-<id>-<random>"; new ones are slugged from their name.
        $this->addSql('ALTER TABLE soiree ADD slug VARCHAR(80) DEFAULT NULL');
        $this->addSql("UPDATE soiree SET slug = 'soiree-' || id || '-' || substr(md5(random()::text || id), 1, 8)");
        $this->addSql('ALTER TABLE soiree ALTER slug SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_SOIREE_SLUG ON soiree (slug)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE collective DROP couleur_accent');
        $this->addSql('ALTER TABLE collective DROP logo');
        $this->addSql('DROP INDEX UNIQ_SOIREE_SLUG');
        $this->addSql('ALTER TABLE soiree DROP slug');
    }
}
