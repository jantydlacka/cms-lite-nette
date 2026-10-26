<?php

declare(strict_types=1);

use Tester\Assert;

require __DIR__ . '/../bootstrap.php';

const PROJECT_ROOT = __DIR__ . '/../..';
const FIXTURES = __DIR__ . '/fixtures/';


/**
 * Runs PHPStan with the real architecture rules over the fixtures.
 *
 * @return array<string, list<string>> fixture file name => sorted error identifiers
 */
function analyse(): array
{
	$process = proc_open(
		['vendor/bin/phpstan', 'analyse', '--error-format=json', '--no-progress', '--memory-limit=1G', '-c', __DIR__ . '/phpstan-fixtures.neon'],
		[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
		$pipes,
		PROJECT_ROOT,
	);
	if ($process === false) {
		throw new RuntimeException('Cannot start PHPStan.');
	}

	$stdout = (string) stream_get_contents($pipes[1]);
	$stderr = (string) stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	proc_close($process);

	/** @var array{errors?: list<string>, files?: array<string, array{messages: list<array{identifier?: string}>}>} $result */
	$result = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
	Assert::same([], $result['errors'] ?? [], 'PHPStan reported general errors: ' . $stderr);

	$byFile = [];
	foreach ($result['files'] ?? [] as $path => $file) {
		$identifiers = [];
		foreach ($file['messages'] as $message) {
			$identifiers[] = $message['identifier'] ?? '';
		}

		sort($identifiers);
		$byFile[basename($path)] = $identifiers;
	}

	return $byFile;
}


$errors = analyse();

test('Model class using Nette\Database is rejected by the allowlist and the denylist', function () use ($errors): void {
	Assert::same(
		['phpat.modelDoesNotUseFrameworkNamespaces', 'phpat.modelUsesOnlyAllowedVendorNamespaces'],
		$errors['ModelWithExplorer.php'] ?? [],
	);
});


test('Model class using App\Core is rejected', function () use ($errors): void {
	Assert::same(
		['phpat.modelDoesNotDependOnApplicationLayers', 'phpat.modelUsesOnlyAllowedVendorNamespaces'],
		$errors['ModelWithCore.php'] ?? [],
	);
});


test('Model class using a Nette presenter is rejected', function () use ($errors): void {
	Assert::same(
		['phpat.modelDoesNotUseFrameworkNamespaces', 'phpat.modelUsesOnlyAllowedVendorNamespaces'],
		$errors['ModelWithPresenter.php'] ?? [],
	);
});


test('a Model database adapter may use Nette\Database', function () use ($errors): void {
	Assert::true(is_file(FIXTURES . 'Database/DatabaseAdapter.php'));
	Assert::false(isset($errors['DatabaseAdapter.php']));
});


test('a Model database adapter using Nette\Forms is rejected', function () use ($errors): void {
	Assert::same(
		['phpat.databaseAdapterUsesOnlyAllowedVendorNamespaces'],
		$errors['DatabaseAdapterWithForms.php'] ?? [],
	);
});


test('Model class using Nette\Utils and Psr\Clock is accepted', function () use ($errors): void {
	Assert::true(is_file(FIXTURES . 'ModelWithAllowedVendors.php'));
	Assert::false(isset($errors['ModelWithAllowedVendors.php']));
});


test('only the four violating fixtures produce errors', function () use ($errors): void {
	$files = array_keys($errors);
	sort($files);
	Assert::same(['DatabaseAdapterWithForms.php', 'ModelWithCore.php', 'ModelWithExplorer.php', 'ModelWithPresenter.php'], $files);
});
