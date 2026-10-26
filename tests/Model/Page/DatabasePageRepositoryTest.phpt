<?php

declare(strict_types=1);

use App\Model\Page\Database\DatabasePageRepository;
use App\Model\Page\InvalidSlugException;
use App\Model\Page\PageRepository;
use App\Model\Page\PageTranslation;
use App\Model\PublicationStatus;
use App\Model\Transaction;
use Nette\Database\Connection;
use Nette\Database\Explorer;
use Nette\Database\UniqueConstraintViolationException;
use Tester\Assert;
use Tests\Model\Page\PageRepositoryContract;
use Tests\Support\FrozenClock;
use Tests\Support\TestDatabase;
use function Tests\container;

require __DIR__ . '/../../bootstrap.php';

$container = container();
$explorer = $container->getByType(Explorer::class);
$clock = new FrozenClock(new DateTimeImmutable('2026-01-15 12:00:00', new DateTimeZone('UTC')));
$repo = new DatabasePageRepository($explorer, $clock);
$reset = static fn () => TestDatabase::prepare($container);

PageRepositoryContract::run($repo, $reset, static fn (DateTimeImmutable $now) => $clock->set($now));

test('the container autowires the database adapter for PageRepository', function () use ($container): void {
	Assert::type(DatabasePageRepository::class, $container->getByType(PageRepository::class));
});

$translation = static fn (int $pageId, string $path): PageTranslation => new PageTranslation($pageId, 'cs', 'T', $path, $path, '', '', PublicationStatus::Draft, new DateTimeImmutable());

test('a clash on the (locale, path) key becomes InvalidSlugException taken and writes nothing', function () use ($repo, $reset, $explorer, $translation): void {
	$reset();
	$first = $repo->createPage(null, 0, true);
	$second = $repo->createPage(null, 1, true);
	$repo->saveTranslation($translation($first, 'o-nas'));

	$e = Assert::exception(fn () => $repo->saveTranslation($translation($second, 'o-nas')), InvalidSlugException::class);
	assert($e instanceof InvalidSlugException);
	Assert::same('taken', $e->reason);
	Assert::same(1, $explorer->table('page_translation')->count('*'));
});

test('a clash on the primary key propagates as the database exception', function () use ($repo, $reset, $container, $explorer, $translation): void {
	$reset();
	$id = $repo->createPage(null, 0, true);
	$params = $container->getParameters();
	assert(is_string($params['dbName']) && is_string($params['dbHost']) && is_string($params['dbPort']) && is_string($params['dbUser']) && is_string($params['dbPassword']));
	$other = new Connection('mysql:host=' . $params['dbHost'] . ';port=' . $params['dbPort'] . ';dbname=' . $params['dbName'], $params['dbUser'], $params['dbPassword']);

	// The snapshot is taken by the first read; the rival commits afterwards, so the adapter still sees no row and inserts.
	Assert::exception(function () use ($container, $explorer, $repo, $other, $id, $translation): void {
		$container->getByType(Transaction::class)->run(function () use ($explorer, $repo, $other, $id, $translation): void {
			$explorer->table('page_translation')->count('*');
			$other->query('INSERT INTO page_translation', [
				'page_id' => $id, 'locale' => 'cs', 'title' => 'R', 'slug' => 'rival', 'path' => 'rival',
				'content' => '', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
			]);
			$repo->saveTranslation($translation($id, 'ours'));
		});
	}, UniqueConstraintViolationException::class);
});
