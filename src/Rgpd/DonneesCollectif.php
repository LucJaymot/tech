<?php

namespace App\Rgpd;

use Doctrine\DBAL\Connection;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * Everything stored for a collective: every table with a collective_id
 * column (found from the schema, so new tables are covered), its members'
 * accounts, and its files in the Flysystem storages.
 */
final class DonneesCollectif
{
    public function __construct(
        private readonly Connection $connection,
        #[Target('pieces_jointes.storage')]
        private readonly FilesystemOperator $piecesJointes,
        #[Target('justificatifs.storage')]
        private readonly FilesystemOperator $justificatifs,
        #[Target('visuels.storage')]
        private readonly FilesystemOperator $visuels,
    ) {
    }

    /**
     * @return array<string, mixed>|null null for an unknown slug
     */
    public function exporter(string $slug): ?array
    {
        $collectif = $this->connection->fetchAssociative('SELECT * FROM collective WHERE slug = ?', [$slug]);
        if (false === $collectif) {
            return null;
        }

        $tables = [];
        foreach ($this->tablesDuCollectif() as $table) {
            $tables[$table] = $this->connection->fetchAllAssociative(\sprintf('SELECT * FROM %s WHERE collective_id = ? ORDER BY id', $this->connection->quoteSingleIdentifier($table)), [$collectif['id']]);
        }

        return [
            'exporte_le' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            'collectif' => $collectif,
            // Never the password hash.
            'comptes_des_membres' => $this->connection->fetchAllAssociative('SELECT u.id, u.email, u.display_name, u.created_at, u.derniere_connexion_le FROM "user" u JOIN membership m ON m.user_id = u.id WHERE m.collective_id = ? ORDER BY u.id', [$collectif['id']]),
            'tables' => $tables,
            'fichiers' => 'Les fichiers (pièces jointes, justificatifs, logo, visuels) sont listés par leur chemin dans les tables ; ils ne sont pas inclus dans cet export.',
        ];
    }

    /**
     * Deletes the collective's files, then its rows (the foreign keys cascade).
     * Safe to run again if it stopped half-way. Accounts are kept: people
     * may belong to other collectives; inactive ones are purged later.
     *
     * @return bool false for an unknown slug
     */
    public function supprimer(string $slug): bool
    {
        $id = $this->connection->fetchOne('SELECT id FROM collective WHERE slug = ?', [$slug]);
        if (false === $id) {
            return false;
        }

        $this->piecesJointes->deleteDirectory((string) $id);
        $this->justificatifs->deleteDirectory((string) $id);
        $this->visuels->deleteDirectory('logos/'.$id);
        foreach ($this->connection->fetchFirstColumn('SELECT slug FROM soiree WHERE collective_id = ?', [$id]) as $soiree) {
            $this->visuels->deleteDirectory('lineups/'.$soiree);
        }

        $this->connection->delete('collective', ['id' => $id]);

        return true;
    }

    /**
     * @return list<string>
     */
    private function tablesDuCollectif(): array
    {
        return array_map('strval', $this->connection->fetchFirstColumn("SELECT table_name FROM information_schema.columns WHERE column_name = 'collective_id' AND table_schema = current_schema() ORDER BY table_name"));
    }
}
