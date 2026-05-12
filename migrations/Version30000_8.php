<?php

declare(strict_types=1);

/*
 * This file is part of SolidInvoice project.
 *
 * (c) Pierre du Plessis <open-source@solidworx.co>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace DoctrineMigrations;

use DateTimeImmutable;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;
use SolidInvoice\TaxBundle\Entity\Tax;
use SolidInvoice\TaxBundle\Entity\TaxIdentifier;
use SolidInvoice\TaxBundle\Enum\TaxCategory;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

final class Version30000_8 extends AbstractMigration
{
    private const COMPANY_VAT_SETTING_KEY = 'system/company/vat_number';

    public function getDescription(): string
    {
        return 'Tax overhaul foundations: extend tax_rates (category/compound) and introduce tax_identifier table with VAT backfill';
    }

    public function isTransactional(): bool
    {
        return ! $this->platform instanceof MySQLPlatform && ! $this->platform instanceof OraclePlatform;
    }

    /**
     * @throws Exception
     */
    public function preUp(Schema $schema): void
    {
        // The tax_identifier table is created in up(); to backfill from clients.vat_number
        // (which is dropped in up()), we must materialise the source data first so postUp
        // can perform inserts after the table exists.
        $this->capturedClientVatNumbers = $this->fetchClientVatNumbers($schema);
        $this->capturedCompanyVatNumbers = $this->fetchCompanyVatNumbers();
    }

    public function up(Schema $schema): void
    {
        $taxTable = $schema->getTable(Tax::TABLE_NAME);

        if (! $taxTable->hasColumn('category')) {
            $taxTable->addColumn('category', Types::STRING, [
                'length' => 32,
                'notnull' => true,
                'default' => TaxCategory::Standard->value,
            ]);
        }

        if (! $taxTable->hasColumn('compound')) {
            $taxTable->addColumn('compound', Types::BOOLEAN, [
                'notnull' => true,
                'default' => false,
            ]);
        }

        if (! $schema->hasTable(TaxIdentifier::TABLE_NAME)) {
            $table = $schema->createTable(TaxIdentifier::TABLE_NAME);

            $table->addColumn('id', UlidType::NAME);
            $table->addColumn('company_id', UlidType::NAME);
            $table->addColumn('client_id', UlidType::NAME, ['notnull' => false]);
            $table->addColumn('label', Types::STRING, ['length' => 32, 'notnull' => true]);
            $table->addColumn('value', Types::STRING, ['length' => 64, 'notnull' => true]);
            $table->addColumn('is_primary', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
            $table->addColumn('created', Types::DATETIME_MUTABLE);
            $table->addColumn('updated', Types::DATETIME_MUTABLE);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['company_id']);
            $table->addIndex(['client_id']);

            $table->addForeignKeyConstraint(
                'companies',
                ['company_id'],
                ['id'],
                ['onDelete' => 'CASCADE'],
            );

            $table->addForeignKeyConstraint(
                'clients',
                ['client_id'],
                ['id'],
                ['onDelete' => 'CASCADE'],
            );
        }

        $clientTable = $schema->getTable('clients');
        if ($clientTable->hasColumn('vat_number')) {
            $clientTable->dropColumn('vat_number');
        }
    }

    /**
     * @throws Exception
     */
    public function postUp(Schema $schema): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        foreach ($this->capturedClientVatNumbers as $row) {
            $this->connection->insert(TaxIdentifier::TABLE_NAME, [
                'id' => (new Ulid())->toBinary(),
                'company_id' => $row['company_id'],
                'client_id' => $row['id'],
                'label' => 'VAT',
                'value' => $row['vat_number'],
                'is_primary' => true,
                'created' => $now,
                'updated' => $now,
            ]);
        }

        foreach ($this->capturedCompanyVatNumbers as $row) {
            $this->connection->insert(TaxIdentifier::TABLE_NAME, [
                'id' => (new Ulid())->toBinary(),
                'company_id' => $row['company_id'],
                'client_id' => null,
                'label' => 'VAT',
                'value' => $row['setting_value'],
                'is_primary' => true,
                'created' => $now,
                'updated' => $now,
            ]);
        }

        $this->connection->delete('app_config', [
            'setting_key' => self::COMPANY_VAT_SETTING_KEY,
        ]);
    }

    public function down(Schema $schema): void
    {
        $taxTable = $schema->getTable(Tax::TABLE_NAME);

        if ($taxTable->hasColumn('compound')) {
            $taxTable->dropColumn('compound');
        }

        if ($taxTable->hasColumn('category')) {
            $taxTable->dropColumn('category');
        }

        if ($schema->hasTable(TaxIdentifier::TABLE_NAME)) {
            $schema->dropTable(TaxIdentifier::TABLE_NAME);
        }

        $clientTable = $schema->getTable('clients');
        if (! $clientTable->hasColumn('vat_number')) {
            $clientTable->addColumn('vat_number', Types::STRING, [
                'length' => 255,
                'notnull' => false,
            ]);
        }
    }

    /**
     * @var list<array{id: string, company_id: string, vat_number: string}>
     */
    private array $capturedClientVatNumbers = [];

    /**
     * @var list<array{company_id: string, setting_value: string}>
     */
    private array $capturedCompanyVatNumbers = [];

    /**
     * @return list<array{id: string, company_id: string, vat_number: string}>
     * @throws Exception
     */
    private function fetchClientVatNumbers(Schema $schema): array
    {
        $clientTable = $schema->getTable('clients');
        if (! $clientTable->hasColumn('vat_number')) {
            return [];
        }

        /** @var list<array{id: string, company_id: string, vat_number: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            "SELECT id, company_id, vat_number FROM clients WHERE vat_number IS NOT NULL AND vat_number != ''"
        );

        return $rows;
    }

    /**
     * @return list<array{company_id: string, setting_value: string}>
     * @throws Exception
     */
    private function fetchCompanyVatNumbers(): array
    {
        /** @var list<array{company_id: string, setting_value: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            "SELECT company_id, setting_value FROM app_config WHERE setting_key = ? AND setting_value IS NOT NULL AND setting_value != ''",
            [self::COMPANY_VAT_SETTING_KEY]
        );

        return $rows;
    }
}
