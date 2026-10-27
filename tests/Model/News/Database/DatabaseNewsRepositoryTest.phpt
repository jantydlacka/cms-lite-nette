<?php

declare(strict_types=1);

use App\Model\InvalidSlugException;
use App\Model\News\Database\DatabaseNewsRepository;
use App\Model\News\NewsRepository;
use App\Model\News\NewsTranslation;
use App\Model\PublicationStatus;
use App\Model\Transaction;
use Nette\Database\Connection;
use Nette\Database\DriverException;
use Nette\Database\Explorer;
use Nette\Database\UniqueConstraintViolationException;
use Tester\Assert;
use Tests\Model\News\NewsRepositoryContract;
use Tests\Support\FrozenClock;
use Tests\Support\TestDatabase;
use function Tests\container;

require __DIR__ . '/../../../bootstrap.php';

$container = container();
$explorer = $container->getByType(Explorer::class);
$clock = new FrozenClock(new DateTimeImmutable('2026-01-15 12:00:00', new DateTimeZone('UTC')));
$repo = new DatabaseNewsRepository($explorer, $clock);
$reset = static fn () => TestDatabase::prepare($container);
// A second session (autocommit) that gives up on a row lock after one second.
$otherConnection = static function () use ($container): Connection {
	$params = $container->getParameters();
	assert(is_string($params['dbName']) && is_string($params['dbHost']) && is_string($params['dbPort']) && is_string($params['dbUser']) && is_string($params['dbPassword']));
	$other = new Connection('mysql:host=' . $params['dbHost'] . ';port=' . $params['dbPort'] . ';dbname=' . $params['dbName'], $params['dbUser'], $params['dbPassword']);
	$other->query('SET SESSION innodb_lock_wait_timeout = 1');

	return $other;
};

NewsRepositoryContract::run($repo, $reset, static fn (DateTimeImmutable $now) => $clock->set($now));

test('the container autowires the database adapter for NewsRepository', function () use ($container): void {
	Assert::type(DatabaseNewsRepository::class, $container->getByType(NewsRepository::class));
});

test('deleting the author keeps the news item and clears author_id', function () use ($repo, $reset, $explorer): void {
	$reset();
	$explorer->query('INSERT INTO `user` ?', ['email' => 'a@example.com', 'password_hash' => 'h', 'name' => 'Ann', 'role' => 'editor', 'created_at' => '2026-01-01 00:00:00']);
	$userId = (int) $explorer->getInsertId();
	$id = $repo->createNews(null, $userId);
	Assert::same($userId, $repo->getNews($id)?->authorId);

	$explorer->query('DELETE FROM `user` WHERE id = ?', $userId);
	Assert::notNull($repo->getNews($id));
	Assert::null($repo->getNews($id)?->authorId);
});

test('deleting a news item cascades to its translation rows', function () use ($repo, $reset, $explorer): void {
	$reset();
	$id = $repo->createNews(null, null);
	$repo->saveTranslation(new NewsTranslation($id, 'cs', 'T', 't', 'P', '', '', PublicationStatus::Draft, new DateTimeImmutable(), 1));
	$repo->saveTranslation(new NewsTranslation($id, 'en', 'T', 't', 'P', '', '', PublicationStatus::Draft, new DateTimeImmutable(), 1));
	$repo->deleteNews($id);

	Assert::same(0, $explorer->table('news_translation')->count('*'));
});

test('a clash on the slug key becomes InvalidSlugException taken', function () use ($repo, $reset, $explorer): void {
	$reset();
	$first = $repo->createNews(null, null);
	$second = $repo->createNews(null, null);
	$repo->saveTranslation(new NewsTranslation($first, 'cs', 'T', 'obsazeny', 'P', '', '', PublicationStatus::Draft, new DateTimeImmutable(), 1));

	$e = Assert::exception(fn () => $repo->saveTranslation(new NewsTranslation($second, 'cs', 'T', 'obsazeny', 'P', '', '', PublicationStatus::Draft, new DateTimeImmutable(), 1)), InvalidSlugException::class);
	assert($e instanceof InvalidSlugException);
	Assert::same(InvalidSlugException::TAKEN, $e->reason);
	Assert::same(1, $explorer->table('news_translation')->count('*'));
});

test('a clash on the primary key propagates as the database exception, not as a taken slug', function () use ($repo, $reset, $container, $explorer, $otherConnection): void {
	$reset();
	$id = $repo->createNews(null, null);
	$other = $otherConnection();

	// The snapshot is taken by the first read; the rival commits afterwards, so the adapter still sees no row and inserts.
	$e = Assert::exception(function () use ($container, $explorer, $repo, $other, $id): void {
		$container->getByType(Transaction::class)->run(function () use ($explorer, $repo, $other, $id): void {
			$explorer->table('news_translation')->count('*');
			$other->query('INSERT INTO news_translation', [
				'news_id' => $id, 'locale' => 'cs', 'title' => 'R', 'slug' => 'rival', 'perex' => 'P',
				'content' => '', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
			]);
			$repo->saveTranslation(new NewsTranslation($id, 'cs', 'T', 'nas', 'P', '', '', PublicationStatus::Draft, new DateTimeImmutable(), 1));
		});
	}, UniqueConstraintViolationException::class); // an InvalidSlugException here fails the type check
	assert($e !== null);
	Assert::contains('PRIMARY', $e->getMessage());
});

test('lockNews holds the row: a second connection cannot update it until the transaction ends', function () use ($repo, $reset, $container, $otherConnection): void {
	$reset();
	$id = $repo->createNews(null, null);
	$other = $otherConnection();

	$container->getByType(Transaction::class)->run(function () use ($repo, $other, $id): void {
		Assert::same($id, $repo->lockNews($id)?->id);
		Assert::exception(fn () => $other->query('UPDATE news SET version = version + 1 WHERE id = ?', $id), DriverException::class, '~[Ll]ock wait timeout~');
	});
	Assert::same(1, $repo->getNews($id)?->version);
});

test('times are stored as UTC text', function () use ($repo, $reset, $explorer): void {
	$reset();
	$id = $repo->createNews(new DateTimeImmutable('2026-01-01 00:30:00', new DateTimeZone('Europe/Prague')), null);

	Assert::same(
		['2025-12-31 23:30:00', '2026-01-15 12:00:00', '2026-01-15 12:00:00'],
		array_values((array) $explorer->query('SELECT CAST(published_at AS CHAR) AS p, CAST(created_at AS CHAR) AS c, CAST(updated_at AS CHAR) AS u FROM news WHERE id = ?', $id)->fetch()),
	);
});
