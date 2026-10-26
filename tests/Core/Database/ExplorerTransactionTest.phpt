<?php

declare(strict_types=1);

use App\Model\Transaction;
use Nette\Database\Connection;
use Nette\Database\Explorer;
use Tester\Assert;
use function Tests\container;

require __DIR__ . '/../../bootstrap.php';

function insertSetting(Explorer $db, string $key): void
{
	$db->query('INSERT INTO setting', ['key' => $key, 'locale' => '', 'value' => 'v']);
}


function settingExists(Explorer $db, string $key): bool
{
	return $db->fetchField('SELECT COUNT(*) FROM setting WHERE `key` = ?', $key) === 1;
}


test('a successful run commits and returns the callback result', function (): void {
	$container = container();
	$db = $container->getByType(Explorer::class);

	$result = $container->getByType(Transaction::class)->run(function () use ($db): string {
		insertSetting($db, 'a');

		return 'done';
	});

	Assert::same('done', $result);
	Assert::true(settingExists($db, 'a'));
});


test('an exception rolls back, propagates unchanged and leaves no open transaction', function (): void {
	$container = container();
	$db = $container->getByType(Explorer::class);

	Assert::exception(function () use ($container, $db): void {
		$container->getByType(Transaction::class)->run(function () use ($db): void {
			insertSetting($db, 'a');

			throw new DomainException('boom');
		});
	}, DomainException::class, 'boom');

	Assert::false(settingExists($db, 'a'));
	Assert::false($container->getByType(Connection::class)->getPdo()->inTransaction());
});


test('an inner exception that the outer code does not catch rolls everything back', function (): void {
	$container = container();
	$db = $container->getByType(Explorer::class);
	$transaction = $container->getByType(Transaction::class);

	Assert::exception(function () use ($db, $transaction): void {
		$transaction->run(function () use ($db, $transaction): void {
			insertSetting($db, 'outer');
			$transaction->run(function () use ($db): void {
				insertSetting($db, 'inner');

				throw new DomainException('inner');
			});
		});
	}, DomainException::class, 'inner');

	Assert::false(settingExists($db, 'outer'));
	Assert::false(settingExists($db, 'inner'));
	Assert::false($container->getByType(Connection::class)->getPdo()->inTransaction());
});


test('an outer run that catches the inner exception commits the inner writes too', function (): void {
	$container = container();
	$db = $container->getByType(Explorer::class);
	$transaction = $container->getByType(Transaction::class);

	$transaction->run(function () use ($db, $transaction): void {
		insertSetting($db, 'outer');
		try {
			$transaction->run(function () use ($db): void {
				insertSetting($db, 'inner');

				throw new DomainException('inner');
			});
		} catch (DomainException) {
		}
	});

	Assert::true(settingExists($db, 'outer'));
	Assert::true(settingExists($db, 'inner'));
});
