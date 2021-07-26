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

namespace SimpleThings\EntityAudit;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use SimpleThings\EntityAudit\Metadata\MetadataFactory;
use SimpleThings\EntityAudit\Exception\ConfigurationNotSetException;

class AuditConfiguration
{
    /**
     * @var string[]
     *
     * @phpstan-var class-string[]
     */
    private array $auditedEntityClasses = [];

    private bool $disableForeignKeys = false;

    private array $entityIgnoredProperties = [];

    /**
     * @var string[]
     */
    private array $globalIgnoreColumns = [];

    private string $tablePrefix = '';

    private string $tableSuffix = '_audit';

    private string $revisionTableName = 'revisions';

    private string $revisionFieldName = 'rev';

    private string $revisionTypeFieldName = 'revtype';

    private string $revisionIdFieldType = Types::INTEGER;

    /**
     * @var callable|null
     */
    private $usernameCallable;
    private $convertEnumToString = false;
    /**
     * @var AbstractPlatform|null
     */
    private $databasePlatform = null;

    /**
     * @param string[] $classes
     *
     * @return AuditConfiguration
     *
     * @phpstan-param class-string[] $classes
     */
    public static function forEntities(array $classes)
    {
        $conf = new self();
        $conf->auditedEntityClasses = $classes;

        return $conf;
    }

    /**
     * @param ClassMetadataInfo<object> $metadata
     *
     * @return string
     */
    public function getTableName(ClassMetadataInfo $metadata)
    {
        $tableName = $metadata->getTableName();

        if (null !== $metadata->getSchemaName()) {
            $tableName = $metadata->getSchemaName().'.'.$tableName;
        }

        return $this->getTablePrefix().$tableName.$this->getTableSuffix();
    }

    public function areForeignKeysDisabled(): bool
    {
        return $this->disableForeignKeys;
    }

    public function setDisabledForeignKeys(bool $disabled): void
    {
        $this->disableForeignKeys = $disabled;
    }

    /**
     * @return string
     */
    public function getTablePrefix()
    {
        return $this->tablePrefix;
    }

    /**
     * @param string $prefix
     */
    public function setTablePrefix($prefix): void
    {
        $this->tablePrefix = $prefix;
    }

    /**
     * @return string
     */
    public function getTableSuffix()
    {
        return $this->tableSuffix;
    }

    /**
     * @param string $suffix
     */
    public function setTableSuffix($suffix): void
    {
        $this->tableSuffix = $suffix;
    }

    /**
     * @return string
     */
    public function getRevisionFieldName()
    {
        return $this->revisionFieldName;
    }

    /**
     * @param string $revisionFieldName
     */
    public function setRevisionFieldName($revisionFieldName): void
    {
        $this->revisionFieldName = $revisionFieldName;
    }

    /**
     * @return string
     */
    public function getRevisionTypeFieldName()
    {
        return $this->revisionTypeFieldName;
    }

    /**
     * @param string $revisionTypeFieldName
     */
    public function setRevisionTypeFieldName($revisionTypeFieldName): void
    {
        $this->revisionTypeFieldName = $revisionTypeFieldName;
    }

    /**
     * @return string
     */
    public function getRevisionTableName()
    {
        return $this->revisionTableName;
    }

    /**
     * @param string $revisionTableName
     */
    public function setRevisionTableName($revisionTableName): void
    {
        $this->revisionTableName = $revisionTableName;
    }

    /**
     * @param string[] $classes
     *
     * @phpstan-param class-string[] $classes
     */
    public function setAuditedEntityClasses(array $classes): void
    {
        $this->auditedEntityClasses = $classes;
    }

    public function getGlobalIgnoreColumns()
    {
        return $this->globalIgnoreColumns;
    }

    /**
     * @param string[] $columns
     */
    public function setGlobalIgnoreColumns(array $columns): void
    {
        $this->globalIgnoreColumns = $columns;
    }

    /**
     * @return MetadataFactory
     */
    public function createMetadataFactory()
    {
        return new MetadataFactory($this->auditedEntityClasses);
    }

    /**
     * @deprecated
     *
     * @param string|null $username
     */
    public function setCurrentUsername($username): void
    {
        $this->setUsernameCallable(static fn () => $username);
    }

    /**
     * @return string
     */
    public function getCurrentUsername()
    {
        $callable = $this->usernameCallable;

        return null !== $callable ? (string) $callable() : '';
    }

    /**
     * @param callable|null $usernameCallable
     */
    public function setUsernameCallable($usernameCallable): void
    {
        // php 5.3 compat
        if (null !== $usernameCallable && !\is_callable($usernameCallable)) {
            throw new \InvalidArgumentException(sprintf('Username Callable must be callable. Got: %s', get_debug_type($usernameCallable)));
        }

        $this->usernameCallable = $usernameCallable;
    }

    /**
     * @return callable|null
     */
    public function getUsernameCallable()
    {
        return $this->usernameCallable;
    }

    /**
     * @param string $revisionIdFieldType
     */
    public function setRevisionIdFieldType($revisionIdFieldType): void
    {
        $this->revisionIdFieldType = $revisionIdFieldType;
    }

    /**
     * @return string
     */
    public function getRevisionIdFieldType()
    {
        return $this->revisionIdFieldType;
    }

    public function setConvertEnumToString(bool $convertEnum): void
    {
        $this->convertEnumToString = $convertEnum;
    }

    public function getConvertEnumToString(): bool
    {
        return $this->convertEnumToString;
    }

    /**
     * @return AbstractPlatform|null
     */
    public function getDatabasePlatform()
    {
        if (true === $this->getConvertEnumToString() && null === $this->databasePlatform) {
            throw new ConfigurationNotSetException('databasePlatform');
        }

        return $this->databasePlatform;
    }

    /**
     * @param AbstractPlatform|null $databasePlatform
     */
    public function setDatabasePlatform($databasePlatform): void
    {
        $this->databasePlatform = $databasePlatform;
    }

    /**
     * @return array<string, string[]>
     */
    final public function getEntityIgnoredProperties($entity): array
    {
        return $this->entityIgnoredProperties[$entity] ?? [];
    }

    /**
     * @param array<string, string[]> $fields
     */
    public function setEntityIgnoredProperties(array $fields): void
    {
        $this->entityIgnoredProperties = $fields;
    }

    public function isEntityIgnoredProperty(string $entity, $propertyName): bool
    {
        return \array_key_exists($entity, $this->getEntityIgnoredProperties($entity)) && \in_array($propertyName, $this->getEntityIgnoredProperties()[$entity], true);
    }
}
