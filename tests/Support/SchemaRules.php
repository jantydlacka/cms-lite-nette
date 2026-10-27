<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Rules every migrated schema must follow. Each rule is a pure function over SchemaDump::read() output
 * and returns its violations as `table.column: reason` lines (empty = satisfied).
 *
 * @phpstan-import-type Schema from SchemaDump
 */
final class SchemaRules
{
	/**
	 * Columns compared byte by byte: a case- or accent-insensitive collation would make `A` and `a` one key.
	 * Besides these names, every CHAR/VARCHAR column of a PRIMARY or UNIQUE key.
	 */
	public const BINARY_COLUMNS = ['slug', 'path', 'locale', 'email', 'ip', 'old_path'];
	public const BINARY_COLLATIONS = ['ascii_bin', 'utf8mb4_bin', 'utf8mb4_0900_bin'];
	public const COLUMN_CHARSETS = ['utf8mb4', 'ascii'];
	/** Every CREATE TABLE in migrations/ states this; columns that need byte comparison override it. */
	public const TABLE_COLLATION = 'utf8mb4_unicode_ci';
	private const KEY_STRING_TYPES = ['char', 'varchar'];
	private const BINARY_TYPES = ['binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob'];

	/**
	 * information_schema reports an omitted ON DELETE as NO ACTION, the same as an explicit NO ACTION (measured on
	 * MySQL 8.4.13), so NO ACTION is refused: a key states RESTRICT, CASCADE or SET NULL. ON UPDATE is deliberately
	 * not checked: keys reference AUTO_INCREMENT ids, which the application never updates.
	 */
	public const DELETE_RULES = ['RESTRICT', 'CASCADE', 'SET NULL'];


	/**
	 * @param Schema $schema
	 * @param array<string, string> $nullableAllowlist `table.column` => reason
	 * @return list<string>
	 */
	public static function all(array $schema, array $nullableAllowlist): array
	{
		return [
			...self::innoDbUtf8mb4($schema),
			...self::foreignKeys($schema),
			...self::binaryCollation($schema),
			...self::datetimeTimestamps($schema),
			...self::noApproximateNumbers($schema),
			...self::nullableOnlyAllowlisted($schema, $nullableAllowlist),
		];
	}


	/**
	 * @param Schema $schema
	 * @return list<string>
	 */
	public static function innoDbUtf8mb4(array $schema): array
	{
		$violations = [];
		foreach ($schema as $name => $table) {
			if ($table['engine'] !== 'InnoDB') {
				$violations[] = $name . ': engine ' . $table['engine'] . ', expected InnoDB';
			}
			if ($table['collation'] !== self::TABLE_COLLATION) {
				$violations[] = $name . ': collation ' . $table['collation'] . ', expected ' . self::TABLE_COLLATION;
			}
			foreach ($table['columns'] as $column => $definition) {
				// Binary and non-text types have no charset.
				if ($definition['charset'] !== null && !in_array($definition['charset'], self::COLUMN_CHARSETS, true)) {
					$violations[] = $name . '.' . $column . ': charset ' . $definition['charset'] . ', expected ' . implode(' / ', self::COLUMN_CHARSETS);
				}
			}
		}

		return $violations;
	}


	/**
	 * Every foreign key needs an index whose leading columns are the key's columns, and an explicit ON DELETE.
	 *
	 * @param Schema $schema
	 * @return list<string>
	 */
	public static function foreignKeys(array $schema): array
	{
		$violations = [];
		foreach ($schema as $name => $table) {
			foreach ($table['foreignKeys'] as $key => $foreignKey) {
				$supported = false;
				foreach ($table['indexes'] as $index) {
					// A prefix (SUB_PART) or functional part cannot serve the key.
					$leading = array_map(
						static fn (array $part): ?string => $part['subPart'] === null ? $part['column'] : null,
						array_slice($index['parts'], 0, count($foreignKey['columns'])),
					);
					if ($leading === $foreignKey['columns']) {
						$supported = true;
						break;
					}
				}
				if (!$supported) {
					$violations[] = $name . '.' . $key . ': no index starts with (' . implode(', ', $foreignKey['columns']) . ')';
				}
				if (!in_array($foreignKey['onDelete'], self::DELETE_RULES, true)) {
					$violations[] = $name . '.' . $key . ': ON DELETE ' . $foreignKey['onDelete'] . ', expected an explicit ' . implode(' / ', self::DELETE_RULES);
				}
			}
		}

		return $violations;
	}


	/**
	 * @param Schema $schema
	 * @return list<string>
	 */
	public static function binaryCollation(array $schema): array
	{
		$violations = [];
		foreach ($schema as $name => $table) {
			$keyColumns = [];
			foreach ($table['indexes'] as $index) {
				if ($index['unique']) {
					foreach ($index['parts'] as $part) {
						$keyColumns[(string) $part['column']] = true;
					}
				}
			}
			foreach ($table['columns'] as $column => $definition) {
				$checked = in_array($column, self::BINARY_COLUMNS, true)
					|| (isset($keyColumns[$column]) && in_array($definition['dataType'], self::KEY_STRING_TYPES, true));
				if ($checked && !in_array($definition['dataType'], self::BINARY_TYPES, true) && !in_array($definition['collation'], self::BINARY_COLLATIONS, true)) {
					$violations[] = $name . '.' . $column . ': collation ' . ($definition['collation'] ?? 'none') . ', expected ' . implode(' / ', self::BINARY_COLLATIONS);
				}
			}
		}

		return $violations;
	}


	/**
	 * @param Schema $schema
	 * @return list<string>
	 */
	public static function datetimeTimestamps(array $schema): array
	{
		$violations = [];
		foreach ($schema as $name => $table) {
			foreach ($table['columns'] as $column => $definition) {
				if (str_ends_with($column, '_at') && $definition['dataType'] !== 'datetime') {
					$violations[] = $name . '.' . $column . ': type ' . $definition['type'] . ', expected DATETIME';
				}
			}
		}

		return $violations;
	}


	/**
	 * @param Schema $schema
	 * @return list<string>
	 */
	public static function noApproximateNumbers(array $schema): array
	{
		$violations = [];
		foreach ($schema as $name => $table) {
			foreach ($table['columns'] as $column => $definition) {
				if (in_array($definition['dataType'], ['float', 'double'], true)) {
					$violations[] = $name . '.' . $column . ': type ' . $definition['type'] . ', FLOAT and DOUBLE are not allowed';
				}
			}
		}

		return $violations;
	}


	/**
	 * Also reports allowlist entries that no longer match a NULL-able column, or that give no reason.
	 *
	 * @param Schema $schema
	 * @param array<string, string> $allowlist `table.column` => reason
	 * @return list<string>
	 */
	public static function nullableOnlyAllowlisted(array $schema, array $allowlist): array
	{
		$violations = [];
		foreach ($schema as $name => $table) {
			foreach ($table['columns'] as $column => $definition) {
				if ($definition['nullable'] && !isset($allowlist[$name . '.' . $column])) {
					$violations[] = $name . '.' . $column . ': NULL-able but not in the allowlist';
				}
			}
		}
		ksort($allowlist, SORT_STRING);
		foreach ($allowlist as $entry => $reason) {
			[$name, $column] = explode('.', $entry, 2) + [1 => ''];
			$definition = $schema[$name]['columns'][$column] ?? null;
			if ($definition === null) {
				$violations[] = 'allowlist ' . $entry . ': no such column';
			} elseif (!$definition['nullable']) {
				$violations[] = 'allowlist ' . $entry . ': column is NOT NULL';
			}
			if (trim($reason) === '') {
				$violations[] = 'allowlist ' . $entry . ': empty reason';
			}
		}

		return $violations;
	}
}
