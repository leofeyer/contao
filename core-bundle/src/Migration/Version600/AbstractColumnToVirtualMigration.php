<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\CoreBundle\Migration\Version600;

use Contao\CoreBundle\Migration\AbstractMigration;
use Contao\CoreBundle\Migration\MigrationResult;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

abstract class AbstractColumnToVirtualMigration extends AbstractMigration
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function shouldRun(): bool
    {
        $schemaManager = $this->connection->createSchemaManager();
        $mapping = $this->getMapping();

        foreach ($mapping as $table => $fields) {
            // Ignore tables that do not exist
            if (!$schemaManager->tablesExist([$table])) {
                continue;
            }

            $tableDefinition = $schemaManager->introspectTableByUnquotedName($table);

            // If there is at least one column that should be a virtual field, run the migration
            if (array_any($fields, static fn ($targetColumn, $field) => $tableDefinition->hasColumn($field))) {
                return true;
            }
        }

        return false;
    }

    public function run(): MigrationResult
    {
        $schemaManager = $this->connection->createSchemaManager();
        $mapping = $this->getMapping();

        foreach ($mapping as $table => $fields) {
            // Ignore tables that do not exist
            if (!$schemaManager->tableExists($table)) {
                continue;
            }

            $tableDefinition = $schemaManager->introspectTableByUnquotedName($table);
            $fields = array_filter($fields, static fn ($targetColumn, $field) => $tableDefinition->hasColumn($field), ARRAY_FILTER_USE_BOTH);

            // If there is at least one column that should be a virtual field, run the migration
            if ([] === $fields) {
                continue;
            }

            $missingTargetColumns = array_filter(array_unique(array_values($fields)), static fn (string $targetColumn) => !$tableDefinition->hasColumn($targetColumn));

            if ([] !== $missingTargetColumns) {
                // Let schema migrations handle the correct column type, it can always convert TEXT to JSON if supported
                $this->connection->executeStatement("ALTER TABLE $table ADD COLUMN `".implode('` text NULL, ADD COLUMN `', $missingTargetColumns)."` text NULL");
            }

            $rows = $this->connection->fetchAllAssociative("SELECT * FROM `$table`");

            foreach ($rows as $row) {
                $jsonData = [];

                foreach ($fields as $field => $targetColumn) {
                    if (null === ($row[$targetColumn] ?? null)) {
                        $jsonData[$targetColumn] = [];
                    } else {
                        $jsonData[$targetColumn] = json_decode($row[$targetColumn], true, flags: JSON_THROW_ON_ERROR);
                    }

                    if (isset($jsonData[$targetColumn][$field])) {
                        continue;
                    }

                    $jsonData[$targetColumn][$field] = StringUtil::ensureStringUuids($row[$field]);
                }

                $this->connection->update(
                    $table,
                    array_map(static fn (array $json) => json_encode($json, flags: JSON_THROW_ON_ERROR), $jsonData),
                    ['id' => $row['id']],
                    array_map(static fn () => Types::JSON, $jsonData),
                );
            }

            $this->connection->executeQuery("ALTER TABLE `$table` DROP COLUMN `".implode('`, DROP COLUMN `', array_keys($fields)).'`');
        }

        return $this->createResult(true);
    }

    /**
     * Returns a mapping of tables and fields that should be converted to virtual fields.
     *
     * Example:
     *   [
     *     'tl_content' => [
     *       'playerStart' => 'jsonData',
     *       'playerStop' => 'jsonData',
     *     ],
     *   ]
     *
     * @return array<string, array<string, string>>
     */
    abstract protected function getMapping(): array;
}
