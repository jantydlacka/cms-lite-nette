<?php

declare(strict_types=1);

namespace Tests\Support;

use Nette\Database\Connection;

/**
 * Deterministic description of the current database's schema, read from information_schema.
 *
 * Tables, indexes and foreign keys are sorted by name; columns keep their ordinal order, which is part of the schema
 * like their types; index and foreign key columns keep their key order. AUTO_INCREMENT counters, row counts and
 * timestamps are left out. The migrations runner's own table is not part of the application schema.
 *
 * @phpstan-type Column array{type: string, dataType: string, nullable: bool, default: ?string, extra: string, charset: ?string, collation: ?string}
 * @phpstan-type IndexPart array{column: ?string, expression: ?string, subPart: ?int, descending: bool}
 * @phpstan-type Index array{unique: bool, type: string, visible: bool, parts: list<IndexPart>}
 * @phpstan-type ForeignKey array{columns: list<string>, referencedTable: string, referencedColumns: list<string>, onDelete: string, onUpdate: string}
 * @phpstan-type Table array{engine: string, collation: string, columns: array<string, Column>, indexes: array<string, Index>, foreignKeys: array<string, ForeignKey>}
 * @phpstan-type Schema array<string, Table>
 */
final class SchemaDump
{
	public const SNAPSHOT_FILE = __DIR__ . '/../../docs/schema.md';
	public const REGENERATE_COMMAND = 'docker compose exec -T app bin/schema-snapshot';
	private const IGNORED_TABLES = ['migrations'];


	/** @return Schema */
	public static function read(Connection $connection): array
	{
		$schema = [];
		$tables = $connection->fetchAll(
			'SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES
			WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = ? ORDER BY TABLE_NAME',
			'BASE TABLE',
		);
		foreach ($tables as $row) {
			$name = self::string($row->TABLE_NAME);
			if (in_array($name, self::IGNORED_TABLES, true)) {
				continue;
			}
			$schema[$name] = [
				'engine' => self::string($row->ENGINE),
				'collation' => self::string($row->TABLE_COLLATION),
				'columns' => [],
				'indexes' => [],
				'foreignKeys' => [],
			];
		}

		$columns = $connection->fetchAll(
			'SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, CHARACTER_SET_NAME, COLLATION_NAME
			FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION',
		);
		foreach ($columns as $row) {
			$table = self::string($row->TABLE_NAME);
			if (!isset($schema[$table])) {
				continue;
			}
			$schema[$table]['columns'][self::string($row->COLUMN_NAME)] = [
				'type' => self::string($row->COLUMN_TYPE),
				'dataType' => self::string($row->DATA_TYPE),
				'nullable' => $row->IS_NULLABLE === 'YES',
				'default' => self::nullableString($row->COLUMN_DEFAULT),
				'extra' => self::string($row->EXTRA),
				'charset' => self::nullableString($row->CHARACTER_SET_NAME),
				'collation' => self::nullableString($row->COLLATION_NAME),
			];
		}

		$indexes = $connection->fetchAll(
			'SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, INDEX_TYPE, IS_VISIBLE, COLUMN_NAME, EXPRESSION, SUB_PART, COLLATION FROM information_schema.STATISTICS
			WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX',
		);
		foreach ($indexes as $row) {
			$table = self::string($row->TABLE_NAME);
			if (!isset($schema[$table])) {
				continue;
			}
			$index = self::string($row->INDEX_NAME);
			$schema[$table]['indexes'][$index]['unique'] = (int) self::string($row->NON_UNIQUE) === 0;
			$schema[$table]['indexes'][$index]['type'] = self::string($row->INDEX_TYPE);
			$schema[$table]['indexes'][$index]['visible'] = $row->IS_VISIBLE === 'YES';
			// A functional index part has no column, only an expression.
			$schema[$table]['indexes'][$index]['parts'][] = [
				'column' => self::nullableString($row->COLUMN_NAME),
				'expression' => self::nullableString($row->EXPRESSION),
				'subPart' => $row->SUB_PART === null ? null : (int) self::string($row->SUB_PART),
				'descending' => $row->COLLATION === 'D',
			];
		}

		$keys = $connection->fetchAll(
			'SELECT k.TABLE_NAME, k.CONSTRAINT_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.DELETE_RULE, r.UPDATE_RULE
			FROM information_schema.KEY_COLUMN_USAGE k
			JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.TABLE_NAME = k.TABLE_NAME AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
			WHERE k.TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL
			ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION',
		);
		foreach ($keys as $row) {
			$table = self::string($row->TABLE_NAME);
			if (!isset($schema[$table])) {
				continue;
			}
			$key = self::string($row->CONSTRAINT_NAME);
			$schema[$table]['foreignKeys'][$key]['columns'][] = self::string($row->COLUMN_NAME);
			$schema[$table]['foreignKeys'][$key]['referencedTable'] = self::string($row->REFERENCED_TABLE_NAME);
			$schema[$table]['foreignKeys'][$key]['referencedColumns'][] = self::string($row->REFERENCED_COLUMN_NAME);
			$schema[$table]['foreignKeys'][$key]['onDelete'] = self::string($row->DELETE_RULE);
			$schema[$table]['foreignKeys'][$key]['onUpdate'] = self::string($row->UPDATE_RULE);
		}

		foreach (array_keys($schema) as $name) {
			ksort($schema[$name]['indexes'], SORT_STRING);
			ksort($schema[$name]['foreignKeys'], SORT_STRING);
		}
		ksort($schema, SORT_STRING);

		return $schema;
	}


	/** @param Schema $schema */
	public static function render(array $schema): string
	{
		$out = "# Database schema\n\n"
			. "Generated by `bin/schema-snapshot` from the structures and basic-data migrations on an empty database;\n"
			. "do not edit by hand. `tests/Migrations/SchemaTest.phpt` fails when the migrations no longer produce it.\n";

		foreach ($schema as $name => $table) {
			$out .= "\n## " . $name . "\n\n";
			$out .= '- engine ' . $table['engine'] . ', collation ' . $table['collation'] . "\n";

			$out .= "\nColumns:\n\n";
			foreach ($table['columns'] as $column => $c) {
				$out .= '- `' . $column . '` ' . $c['type']
					. ($c['nullable'] ? ' NULL' : ' NOT NULL')
					. ($c['default'] === null ? '' : ' DEFAULT ' . self::quote($c['default']))
					. ($c['extra'] === '' ? '' : ' ' . $c['extra'])
					. ($c['collation'] === null ? '' : ' ' . $c['charset'] . '/' . $c['collation'])
					. "\n";
			}

			$out .= "\nIndexes:\n\n";
			foreach ($table['indexes'] as $index => $i) {
				$parts = array_map(
					static fn (array $p): string => ($p['column'] === null ? (string) $p['expression'] : '`' . $p['column'] . '`')
						. ($p['subPart'] === null ? '' : '(' . $p['subPart'] . ')')
						. ($p['descending'] ? ' DESC' : ''),
					$i['parts'],
				);
				$out .= '- `' . $index . '`' . ($i['unique'] ? ' UNIQUE' : '') . ' ' . $i['type'] . ' (' . implode(', ', $parts) . ')'
					. ($i['visible'] ? '' : ' INVISIBLE') . "\n";
			}

			if ($table['foreignKeys'] !== []) {
				$out .= "\nForeign keys:\n\n";
				foreach ($table['foreignKeys'] as $key => $k) {
					$out .= '- `' . $key . '` (' . self::names($k['columns']) . ') → `' . $k['referencedTable'] . '` (' . self::names($k['referencedColumns']) . ')'
						. ' ON DELETE ' . $k['onDelete'] . ' ON UPDATE ' . $k['onUpdate'] . "\n";
				}
			}
		}

		return $out;
	}


	/** @param list<string> $names */
	private static function names(array $names): string
	{
		return implode(', ', array_map(static fn (string $name): string => '`' . $name . '`', $names));
	}


	private static function quote(string $value): string
	{
		return "'" . str_replace("'", "''", $value) . "'";
	}


	private static function string(mixed $value): string
	{
		assert(is_scalar($value));

		return (string) $value;
	}


	private static function nullableString(mixed $value): ?string
	{
		return $value === null ? null : self::string($value);
	}
}
