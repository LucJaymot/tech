<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261013160751 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Opening and closing of soirées as full date-times, the closing possibly on the next day';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        // Closing first, while the opening is still an hour: not after it means the next day.
        $this->addSql("ALTER TABLE soiree ALTER fermeture TYPE TIMESTAMP(0) WITHOUT TIME ZONE USING date + fermeture + CASE WHEN fermeture <= ouverture THEN INTERVAL '1 day' ELSE INTERVAL '0' END");
        $this->addSql('ALTER TABLE soiree ALTER ouverture TYPE TIMESTAMP(0) WITHOUT TIME ZONE USING date + ouverture');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE soiree ALTER ouverture TYPE TIME(0) WITHOUT TIME ZONE USING ouverture::time');
        $this->addSql('ALTER TABLE soiree ALTER fermeture TYPE TIME(0) WITHOUT TIME ZONE USING fermeture::time');
    }
}
