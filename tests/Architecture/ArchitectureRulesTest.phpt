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


test('Presentation and Core using a model database adapter are rejected', function () use ($errors): void {
	Assert::same(['phpat.layersDoNotDependOnDatabaseAdapters'], $errors['PresentationWithAdapter.php'] ?? []);
	Assert::same(['phpat.layersDoNotDependOnDatabaseAdapters'], $errors['CoreWithAdapter.php'] ?? []);
});


test('Presentation and Core using a model class that is not an adapter are accepted', function () use ($errors): void {
	Assert::true(is_file(FIXTURES . 'Presentation/PresentationWithModelClass.php'));
	Assert::true(is_file(FIXTURES . 'Core/CoreWithModelClass.php'));
	Assert::false(isset($errors['PresentationWithModelClass.php']));
	Assert::false(isset($errors['CoreWithModelClass.php']));
});


test('Model class using another domain\'s database adapter is rejected', function () use ($errors): void {
	Assert::same(['phpat.modelDoesNotDependOnOtherDomainAdaptersLogin'], $errors['LoginWithUserAdapter.php'] ?? []);
});


test('Model class using another domain\'s record or its own adapter is accepted', function () use ($errors): void {
	Assert::true(is_file(FIXTURES . 'Login/LoginWithUserRecord.php'));
	Assert::true(is_file(FIXTURES . 'Login/LoginWithOwnAdapter.php'));
	Assert::false(isset($errors['LoginWithUserRecord.php']));
	Assert::false(isset($errors['LoginWithOwnAdapter.php']));
});


test('Root-level Model class using a database adapter is rejected', function () use ($errors): void {
	Assert::same(['phpat.rootModelDoesNotDependOnDatabaseAdapters'], $errors['RootModelWithAdapter.php'] ?? []);
});


test('Root-level Model class using a domain record is accepted', function () use ($errors): void {
	Assert::true(is_file(FIXTURES . 'Root/RootModelWithRecord.php'));
	Assert::false(isset($errors['RootModelWithRecord.php']));
});


test('Core using Presentation is rejected', function () use ($errors): void {
	Assert::same(['phpat.coreDoesNotDependOnPresentation'], $errors['CoreWithPresentation.php'] ?? []);
});


test('only the violating fixtures produce errors', function () use ($errors): void {
	$files = array_keys($errors);
	sort($files);
	Assert::same([
		'CoreWithAdapter.php',
		'CoreWithPresentation.php',
		'DatabaseAdapterWithForms.php',
		'LoginWithUserAdapter.php',
		'ModelWithCore.php',
		'ModelWithExplorer.php',
		'ModelWithPresenter.php',
		'PresentationWithAdapter.php',
		'RootModelWithAdapter.php',
	], $files);
});
