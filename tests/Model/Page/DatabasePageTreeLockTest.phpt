<?php

declare(strict_types=1);

use App\Model\Page\Database\DatabasePageTreeLock;
use App\Model\Page\PageTreeLock;
use App\Model\Transaction;
use Nette\Database\Connection;
use Nette\Database\DriverException;
use Nette\Database\Explorer;
use Tester\Assert;
use Tests\Support\TestDatabase;
use function Tests\container;

require __DIR__ . '/../../bootstrap.php';

$container = container();
$explorer = $container->getByType(Explorer::class);
$lock = new DatabasePageTreeLock($explorer);
$transaction = $container->getByType(Transaction::class);

test('the container autowires the database adapter for PageTreeLock', function () use ($container): void {
	Assert::type(DatabasePageTreeLock::class, $container->getByType(PageTreeLock::class));
});

test('acquire() outside a transaction fails', function () use ($lock, $container): void {
	TestDatabase::prepare($container);
	Assert::exception(fn () => $lock->acquire(), LogicException::class);
});

test('acquire() inside a transaction succeeds on a prepared database', function () use ($lock, $transaction, $container): void {
	TestDatabase::prepare($container);
	$transaction->run(function () use ($lock): void {
		$lock->acquire();
	});
	Assert::true(true);
});

test('acquire() fails when the lock row is missing', function () use ($lock, $transaction, $container, $explorer): void {
	TestDatabase::prepare($container);
	$explorer->query('DELETE FROM page_tree_lock');
	Assert::exception(fn () => $transaction->run(function () use ($lock): void {
		$lock->acquire();
	}), UnexpectedValueException::class);
	TestDatabase::prepare($container);
});

test('a second connection waits for the lock', function () use ($lock, $transaction, $container): void {
	TestDatabase::prepare($container);
	$params = $container->getParameters();
	assert(is_string($params['dbName']) && is_string($params['dbHost']) && is_string($params['dbPort']) && is_string($params['dbUser']) && is_string($params['dbPassword']));
	$other = new Connection('mysql:host=' . $params['dbHost'] . ';port=' . $params['dbPort'] . ';dbname=' . $params['dbName'], $params['dbUser'], $params['dbPassword']);

	try {
		$transaction->run(function () use ($lock, $other): void {
			$lock->acquire();
			$other->query('SET SESSION innodb_lock_wait_timeout = 1');
			$other->beginTransaction();
			try {
				Assert::exception(fn () => $other->query('SELECT id FROM page_tree_lock WHERE id = 1 FOR UPDATE'), DriverException::class, '~[Ll]ock wait timeout~');
			} finally {
				$other->rollBack();
			}
		});
	} finally {
		TestDatabase::prepare($container);
	}
});
