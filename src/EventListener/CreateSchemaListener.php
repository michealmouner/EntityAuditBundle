<?php

declare(strict_types=1);

/*
 * This file is part of the Sonata Project package.
 *
 * (c) Thomas Rabaix <thomas.rabaix@sonata-project.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SimpleThings\EntityAudit\EventListener;

use Doctrine\Common\EventSubscriber;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Doctrine\ORM\Tools\ToolEvents;
use SimpleThings\EntityAudit\AuditConfiguration;
use SimpleThings\EntityAudit\AuditManager;
use SimpleThings\EntityAudit\Metadata\MetadataFactory;

/**
 * NEXT_MAJOR: do not implement EventSubscriber interface anymore.
 */
class CreateSchemaListener implements EventSubscriber
{
    private AuditConfiguration $config;

    private MetadataFactory $metadataFactory;

    /**
     * @var string[]
     */
    private array $defferedJoinTablesToCreate = [];

    public function __construct(AuditManager $auditManager)
    {
        $this->config = $auditManager->getConfiguration();
        $this->metadataFactory = $auditManager->getMetadataFactory();
    }

    /**
     * NEXT_MAJOR: remove this method.
     *
     * @return string[]
     */
    #[\ReturnTypeWillChange]
    public function getSubscribedEvents()
    {
        return [
            ToolEvents::postGenerateSchemaTable,
            ToolEvents::postGenerateSchema,
        ];
    }

    public function postGenerateSchemaTable(GenerateSchemaTableEventArgs $eventArgs): void
    {
        $cm = $eventArgs->getClassMetadata();

        if (!$this->metadataFactory->isAudited($cm->name)) {
            $audited = false;
            if ($cm->isInheritanceTypeJoined() && $cm->rootEntityName === $cm->name) {
                foreach ($cm->subClasses as $subClass) {
                    if ($this->metadataFactory->isAudited($subClass)) {
                        $audited = true;

                        break;
                    }
                }
            }
            if (!$audited) {
                return;
            }
        }

        $schema = $eventArgs->getSchema();

        $revisionsTable = $this->createRevisionsTable($schema);

        $entityTable = $eventArgs->getClassTable();
        $revisionTable = $schema->createTable(
            $this->config->getTablePrefix().$entityTable->getName().$this->config->getTableSuffix()
        );

        foreach ($entityTable->getColumns() as $column) {
            foreach ($cm->subClasses as $subClass) {
                if ($cm->hasField($column->getName()) || $cm->hasAssociation($column->getName())) {
                    if ($this->config->isEntityIgnoredProperty(
                        $subClass,
                        $cm->getFieldForColumn($column->getName())
                    )) {
                        continue 2;
                    }
                }
            }
            if (empty($cm->discriminatorColumn) && $this->config->isEntityIgnoredProperty($cm->getName(), $cm->getFieldForColumn($column->getName()))) {
                continue;
            }

            $this->addColumnToTable($column, $revisionTable);
        }
        $revisionTable->addColumn($this->config->getRevisionFieldName(), $this->config->getRevisionIdFieldType());
        $revisionTable->addColumn($this->config->getRevisionTypeFieldName(), Types::STRING, ['length' => 4]);
        if (!\in_array($cm->inheritanceType, [ClassMetadataInfo::INHERITANCE_TYPE_NONE, ClassMetadataInfo::INHERITANCE_TYPE_JOINED, ClassMetadataInfo::INHERITANCE_TYPE_SINGLE_TABLE], true)) {
            throw new \Exception(sprintf('Inheritance type "%s" is not yet supported', $cm->inheritanceType));
        }

        $primaryKey = $entityTable->getPrimaryKey();
        \assert(null !== $primaryKey);
        $pkColumns = $primaryKey->getColumns();
        $pkColumns[] = $this->config->getRevisionFieldName();
        $revisionTable->setPrimaryKey($pkColumns);
        $revIndexName = $this->config->getRevisionFieldName().'_'.md5($revisionTable->getName()).'_idx';
        $revisionTable->addIndex([$this->config->getRevisionFieldName()], $revIndexName);

        foreach ($cm->associationMappings as $associationMapping) {
            if ($associationMapping['isOwningSide'] && isset($associationMapping['joinTable'])) {
                if (isset($associationMapping['joinTable']['name'])) {
                    if ($schema->hasTable($associationMapping['joinTable']['name'])) {
                        $this->createRevisionJoinTableForJoinTable($schema, $associationMapping['joinTable']['name']);
                    } else {
                        $this->defferedJoinTablesToCreate[] = $associationMapping['joinTable']['name'];
                    }
                }
            }
        }

        if (!$this->config->areForeignKeysDisabled()) {
            $this->createForeignKeys($revisionTable, $revisionsTable);
        }
    }

    public function postGenerateSchema(GenerateSchemaEventArgs $eventArgs): void
    {
        $schema = $eventArgs->getSchema();
        $this->createRevisionsTable($schema);

        foreach ($this->defferedJoinTablesToCreate as $defferedJoinTableToCreate) {
            $this->createRevisionJoinTableForJoinTable($schema, $defferedJoinTableToCreate);
        }
    }

    private function createForeignKeys(Table $relatedTable, Table $revisionsTable): void
    {
        $revisionForeignKeyName = $this->config->getRevisionFieldName().'_'.md5($relatedTable->getName()).'_fk';
        $primaryKey = $revisionsTable->getPrimaryKey();
        \assert(null !== $primaryKey);

        $relatedTable->addForeignKeyConstraint(
            $revisionsTable,
            [$this->config->getRevisionFieldName()],
            $primaryKey->getColumns(),
            [],
            $revisionForeignKeyName
        );
    }

    /**
     * Copies $column to another table. All its options are copied but notnull and autoincrement which are set to false.
     */
    private function addColumnToTable(Column $column, Table $targetTable): void
    {
        $columnName = $column->getName();
        $columnTypeName = Type::getTypeRegistry()->lookupName($column->getType());
        $columnArrayOptions = array_filter(
            $column->toArray(),
            static function ($key) {
                return !\in_array(
                    $key,
                    ['name', 'version', 'secondPrecision', 'enumType', 'jsonb'],
                    true
                );
            },
            \ARRAY_FILTER_USE_KEY
        );
        // Change Enum type to String.
        if (null !== $this->config->getDatabasePlatform()) {
            $sqlString = $column->getType()->getSQLDeclaration($columnArrayOptions, $this->config->getDatabasePlatform());
            if ($this->config->getConvertEnumToString() && str_contains($sqlString, 'ENUM')) {
                $columnTypeName = Types::STRING;
                $columnArrayOptions['type'] = Type::getType($columnTypeName);
            }
        }

        $targetTable->addColumn($column->getName(), $columnTypeName, array_merge(
            $columnArrayOptions,
            ['notnull' => false, 'autoincrement' => false]
        ));

        $targetColumn = $targetTable->getColumn($columnName);
        $targetColumn->setLength($column->getLength());
        $targetColumn->setPrecision($column->getPrecision());
        $targetColumn->setScale($column->getScale());
        $targetColumn->setUnsigned($column->getUnsigned());
        $targetColumn->setFixed($column->getFixed());
        $targetColumn->setDefault($column->getDefault());
        $targetColumn->setColumnDefinition($column->getColumnDefinition());
        $targetColumn->setComment($column->getComment());
        $targetColumn->setPlatformOptions($column->getPlatformOptions());

        $targetColumn->setNotnull(false);
        $targetColumn->setAutoincrement(false);
    }

    private function createRevisionsTable(Schema $schema): Table
    {
        $revisionsTableName = $this->config->getRevisionTableName();

        if ($schema->hasTable($revisionsTableName)) {
            return $schema->getTable($revisionsTableName);
        }

        $revisionsTable = $schema->createTable($revisionsTableName);
        $revisionsTable->addColumn('id', $this->config->getRevisionIdFieldType(), [
            'autoincrement' => true,
        ]);
        $revisionsTable->addColumn('timestamp', Types::DATETIME_MUTABLE);
        $revisionsTable->addColumn('username', Types::STRING)->setNotnull(false);
        $revisionsTable->setPrimaryKey(['id']);

        return $revisionsTable;
    }

    private function createRevisionJoinTableForJoinTable(Schema $schema, string $joinTableName): void
    {
        $joinTable = $schema->getTable($joinTableName);
        $revisionJoinTableName = $this->config->getTablePrefix().$joinTable->getName().$this->config->getTableSuffix();

        if ($schema->hasTable($revisionJoinTableName)) {
            return;
        }

        $typeRegistry = Type::getTypeRegistry();
        $revisionJoinTable = $schema->createTable(
            $this->config->getTablePrefix().$joinTable->getName().$this->config->getTableSuffix()
        );
        foreach ($joinTable->getColumns() as $column) {
            /* @var Column $column */
            $revisionJoinTable->addColumn(
                $column->getName(),
                $typeRegistry->lookupName($column->getType()),
                ['notnull' => false, 'autoincrement' => false]
            );
        }
        $revisionJoinTable->addColumn($this->config->getRevisionFieldName(), $this->config->getRevisionIdFieldType());
        $revisionJoinTable->addColumn($this->config->getRevisionTypeFieldName(), 'string', ['length' => 4]);

        $pk = $joinTable->getPrimaryKey();
        $pkColumns = null !== $pk ? $pk->getColumns() : [];
        $pkColumns[] = $this->config->getRevisionFieldName();
        $revisionJoinTable->setPrimaryKey($pkColumns);
        $revIndexName = $this->config->getRevisionFieldName().'_'.md5($revisionJoinTable->getName()).'_idx';
        $revisionJoinTable->addIndex([$this->config->getRevisionFieldName()], $revIndexName);
    }
}
