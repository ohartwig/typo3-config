<?php

declare(strict_types=1);

namespace Moselwal\Tests\Fixtures;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;

/**
 * SQLite connection for tests whose subject calls inSet() with a placeholder.
 *
 * mfc/oauth2 maps access levels to be_groups with
 * inSet('gitlabGroup', createNamedParameter($level)). TYPO3 refuses that on
 * SQLite only (MariaDB, MySQL and PostgreSQL accept it), so the expression is
 * written out here in the form SQLite understands.
 */
class SqliteConnection extends Connection
{
    private ?ExpressionBuilder $placeholderAwareExpressionBuilder = null;

    public function getExpressionBuilder(): ExpressionBuilder
    {
        return $this->placeholderAwareExpressionBuilder ??= new class ($this) extends ExpressionBuilder {
            public function inSet(string $fieldName, string $value, bool $isColumn = false): string
            {
                if (
                    $this->connection->getDatabasePlatform() instanceof SQLitePlatform
                    && (str_starts_with($value, ':') || $value === '?')
                ) {
                    return sprintf(
                        "instr(',' || %s || ',', ',' || %s || ',')",
                        $this->connection->quoteIdentifier($fieldName),
                        $value
                    );
                }

                return parent::inSet($fieldName, $value, $isColumn);
            }
        };
    }
}
