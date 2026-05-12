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

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;
use SolidInvoice\TaxBundle\Entity\Tax;
use SolidInvoice\TaxBundle\Enum\TaxCategory;

final class Version30000_8 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add category and compound columns to tax_rates for tax-overhaul foundational enums';
    }

    public function isTransactional(): bool
    {
        return ! $this->platform instanceof MySQLPlatform && ! $this->platform instanceof OraclePlatform;
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable(Tax::TABLE_NAME);

        if (! $table->hasColumn('category')) {
            $table->addColumn('category', Types::STRING, [
                'length' => 32,
                'notnull' => true,
                'default' => TaxCategory::Standard->value,
            ]);
        }

        if (! $table->hasColumn('compound')) {
            $table->addColumn('compound', Types::BOOLEAN, [
                'notnull' => true,
                'default' => false,
            ]);
        }
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable(Tax::TABLE_NAME);

        if ($table->hasColumn('compound')) {
            $table->dropColumn('compound');
        }

        if ($table->hasColumn('category')) {
            $table->dropColumn('category');
        }
    }
}
