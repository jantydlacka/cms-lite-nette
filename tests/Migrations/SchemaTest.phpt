<?php

declare(strict_types=1);

/** Schema snapshot (docs/schema.md) and schema rules over a database built by the migrations alone. */

use App\Bootstrap;
use Tester\Assert;
use Tests\Support\SchemaDump;
use Tests\Support\SchemaRules;
use Tests\Support\TestDatabase;

require __DIR__ . '/../bootstrap.php';

$container = Bootstrap::bootForTests()->createContainer();
$schema = TestDatabase::migratedSchema($container, '_schema');


test('the migrations produce the committed schema snapshot', function () use ($schema): void {
	$expected = is_file(SchemaDump::SNAPSHOT_FILE) ? file_get_contents(SchemaDump::SNAPSHOT_FILE) : '(missing)';
	$actual = SchemaDump::render($schema);

	Assert::same(
		$expected,
		$actual,
		'docs/schema.md does not match the migrations. If the change is intended, regenerate it with `'
			. SchemaDump::REGENERATE_COMMAND . '` and commit the diff.',
	);
});


test('a column added by a later migration breaks the snapshot', function () use ($container, $schema): void {
	$changed = TestDatabase::migratedSchema($container, '_schema', [__DIR__ . '/fixtures/extra-column']);

	Assert::notSame(SchemaDump::render($schema), SchemaDump::render($changed));
	Assert::same('varchar(50)', $changed['page']['columns']['note']['type'] ?? null);
});



test('a changed index breaks the snapshot, functional indexes included', function () use ($container, $schema): void {
	$changed = SchemaDump::render(TestDatabase::migratedSchema($container, '_schema', [__DIR__ . '/fixtures/index-change']));

	Assert::notSame(SchemaDump::render($schema), $changed);
	Assert::contains("- `uq_page_translation_path` UNIQUE BTREE (`locale`, `path`(200))\n", $changed);
	Assert::contains("- `idx_page_order` BTREE (`position` DESC) INVISIBLE\n", $changed);
	Assert::contains("- `idx_page_expr` BTREE ((`position` + 1))\n", $changed);
});


test('a table that relies on the default collation shows up with the server default, not the expected one', function () use ($container): void {
	// The schema database is created without a collation; were it utf8mb4_unicode_ci, a CREATE TABLE without COLLATE would look right.
	$changed = TestDatabase::migratedSchema($container, '_schema', [__DIR__ . '/fixtures/default-collation']);

	Assert::notSame('utf8mb4_unicode_ci', $changed['probe']['collation'] ?? null);
});

/**
 * A one-table schema that satisfies every rule with the defaults; each test below breaks one of them.
 *
 * @param array<string, array{type: string, dataType: string, nullable: bool, default: ?string, extra: string, charset: ?string, collation: ?string}> $columns added to or replacing the defaults
 * @param list<string> $parentIndex
 * @param array<string, array{unique: bool, type: string, visible: bool, parts: list<array{column: ?string, expression: ?string, subPart: ?int, descending: bool}>}> $indexes added to the defaults
 * @return array<string, array{engine: string, collation: string, columns: array<string, array{type: string, dataType: string, nullable: bool, default: ?string, extra: string, charset: ?string, collation: ?string}>, indexes: array<string, array{unique: bool, type: string, visible: bool, parts: list<array{column: ?string, expression: ?string, subPart: ?int, descending: bool}>}>, foreignKeys: array<string, array{columns: list<string>, referencedTable: string, referencedColumns: list<string>, onDelete: string, onUpdate: string}>}>
 */
function schema(
	string $engine = 'InnoDB',
	string $collation = 'utf8mb4_unicode_ci',
	array $columns = [],
	array $parentIndex = ['parent_id', 'id'],
	string $onDelete = 'RESTRICT',
	array $indexes = [],
): array {
	return [
		'item' => [
			'engine' => $engine,
			'collation' => $collation,
			'columns' => array_merge([
				'id' => column('int unsigned', 'int'),
				'parent_id' => column('int unsigned', 'int'),
				'slug' => column('varchar(100)', 'varchar', collation: 'ascii_bin'),
				'title' => column('varchar(200)', 'varchar', collation: 'utf8mb4_unicode_ci'),
				'created_at' => column('datetime', 'datetime'),
			], $columns),
			'indexes' => array_merge([
				'PRIMARY' => index(['id'], unique: true),
				'idx_item_parent' => index($parentIndex),
			], $indexes),
			'foreignKeys' => [
				'fk_item_parent' => ['columns' => ['parent_id'], 'referencedTable' => 'item', 'referencedColumns' => ['id'], 'onDelete' => $onDelete, 'onUpdate' => 'NO ACTION'],
			],
		],
	];
}


/** @return array{type: string, dataType: string, nullable: bool, default: ?string, extra: string, charset: ?string, collation: ?string} */
function column(string $type, string $dataType, bool $nullable = false, ?string $collation = null): array
{
	return [
		'type' => $type,
		'dataType' => $dataType,
		'nullable' => $nullable,
		'default' => null,
		'extra' => '',
		'charset' => $collation === null ? null : explode('_', $collation)[0],
		'collation' => $collation,
	];
}


/**
 * @param list<string> $columns
 * @return array{unique: bool, type: string, visible: bool, parts: list<array{column: ?string, expression: ?string, subPart: ?int, descending: bool}>}
 */
function index(array $columns, bool $unique = false, ?int $subPart = null): array
{
	return [
		'unique' => $unique,
		'type' => 'BTREE',
		'visible' => true,
		'parts' => array_map(static fn (string $column): array => ['column' => $column, 'expression' => null, 'subPart' => $subPart, 'descending' => false], $columns),
	];
}


test('the synthetic schema satisfies every rule', function (): void {
	Assert::same([], SchemaRules::all(schema(), []));
});


test('a table outside InnoDB or utf8mb4 is reported', function (): void {
	Assert::same(
		['item: engine MyISAM, expected InnoDB', 'item: collation latin1_swedish_ci, expected utf8mb4'],
		SchemaRules::innoDbUtf8mb4(schema(engine: 'MyISAM', collation: 'latin1_swedish_ci')),
	);
});


test('a column outside utf8mb4 and ascii is reported', function (): void {
	Assert::same(
		['item.title: charset latin1, expected utf8mb4 / ascii'],
		SchemaRules::innoDbUtf8mb4(schema(columns: ['title' => column('varchar(200)', 'varchar', collation: 'latin1_swedish_ci')])),
	);
});


test('a foreign key without a supporting index is reported', function (): void {
	// An index on (id, parent_id) does not serve lookups by parent_id alone.
	Assert::same(['item.fk_item_parent: no index starts with (parent_id)'], SchemaRules::foreignKeys(schema(parentIndex: ['id', 'parent_id'])));
});


test('a prefix index does not support a foreign key', function (): void {
	$schema = schema(parentIndex: ['id'], indexes: ['idx_item_parent_prefix' => index(['parent_id'], subPart: 4)]);

	Assert::same(['item.fk_item_parent: no index starts with (parent_id)'], SchemaRules::foreignKeys($schema));
});


test('a foreign key without an explicit ON DELETE is reported', function (): void {
	Assert::same(
		['item.fk_item_parent: ON DELETE NO ACTION, expected an explicit RESTRICT / CASCADE / SET NULL'],
		SchemaRules::foreignKeys(schema(onDelete: 'NO ACTION')),
	);
});


test('a case-insensitive slug, path, locale, email, ip or old_path is reported', function (): void {
	foreach (SchemaRules::BINARY_COLUMNS as $name) {
		$schema = schema(columns: [$name => column('varchar(100)', 'varchar', collation: 'utf8mb4_unicode_ci')]);

		Assert::same(['item.' . $name . ': collation utf8mb4_unicode_ci, expected ascii_bin / utf8mb4_bin / utf8mb4_0900_bin'], SchemaRules::binaryCollation($schema), $name);
	}
});


test('a CHAR or VARCHAR column in a PRIMARY or UNIQUE key needs a binary collation, whatever its name', function (): void {
	$schema = schema(
		columns: ['code' => column('varchar(20)', 'varchar', collation: 'utf8mb4_unicode_ci'), 'kind' => column('char(2)', 'char', collation: 'utf8mb4_0900_ai_ci')],
		indexes: ['uq_item_code' => index(['code', 'id'], unique: true), 'idx_item_kind' => index(['kind'])],
	);

	// `kind` is only in a non-unique index: it may compare case-insensitively.
	Assert::same(['item.code: collation utf8mb4_unicode_ci, expected ascii_bin / utf8mb4_bin / utf8mb4_0900_bin'], SchemaRules::binaryCollation($schema));
});


test('binary column types and utf8mb4_0900_bin satisfy the binary collation rule', function (): void {
	$schema = schema(
		columns: ['path' => column('varbinary(500)', 'varbinary'), 'ip' => column('binary(16)', 'binary'), 'email' => column('varchar(254)', 'varchar', collation: 'utf8mb4_0900_bin')],
		indexes: ['uq_item_path' => index(['path'], unique: true)],
	);

	Assert::same([], SchemaRules::binaryCollation($schema));
});


test('a time column that is not DATETIME is reported', function (): void {
	Assert::same(
		['item.created_at: type timestamp, expected DATETIME'],
		SchemaRules::datetimeTimestamps(schema(columns: ['created_at' => column('timestamp', 'timestamp')])),
	);
});


test('a FLOAT or DOUBLE column is reported', function (): void {
	Assert::same(
		['item.price: type float, FLOAT and DOUBLE are not allowed', 'item.rate: type double, FLOAT and DOUBLE are not allowed'],
		SchemaRules::noApproximateNumbers(schema(columns: ['price' => column('float', 'float'), 'rate' => column('double', 'double')])),
	);
});


test('a NULL-able column outside the allowlist is reported', function (): void {
	$schema = schema(columns: ['parent_id' => column('int unsigned', 'int', nullable: true)]);

	Assert::same(['item.parent_id: NULL-able but not in the allowlist'], SchemaRules::nullableOnlyAllowlisted($schema, []));
	Assert::same([], SchemaRules::nullableOnlyAllowlisted($schema, ['item.parent_id' => 'top-level items have no parent']));
});


test('a stale or unexplained allowlist entry is reported', function (): void {
	$schema = schema(columns: ['parent_id' => column('int unsigned', 'int', nullable: true)]);

	Assert::same(
		[
			'allowlist item.parent_id: empty reason',
			'allowlist item.removed: no such column',
			'allowlist item.title: column is NOT NULL',
		],
		SchemaRules::nullableOnlyAllowlisted($schema, ['item.parent_id' => ' ', 'item.removed' => 'gone', 'item.title' => 'was NULL once']),
	);
});
