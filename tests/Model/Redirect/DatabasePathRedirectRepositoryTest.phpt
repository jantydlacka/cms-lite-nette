<?php

declare(strict_types=1);

use App\Model\Redirect\Database\DatabasePathRedirectRepository;
use App\Model\Redirect\PathRedirectRepository;
use App\Model\Redirect\RedirectTarget;
use Nette\Database\Explorer;
use Tester\Assert;
use Tests\Support\FrozenClock;
use Tests\Support\TestDatabase;
use function Tests\container;

require __DIR__ . '/../../bootstrap.php';

$container = container();
$explorer = $container->getByType(Explorer::class);
$clock = new FrozenClock(new DateTimeImmutable('2026-01-15 12:00:00', new DateTimeZone('UTC')));
$repo = new DatabasePathRedirectRepository($explorer, $clock);
$reset = static fn () => TestDatabase::prepare($container);

test('findTarget honours locale and exact path', function () use ($repo, $reset): void {
	$reset();
	$repo->record('cs', 'o-nas', new RedirectTarget('page', 5));

	$target = $repo->findTarget('cs', 'o-nas');
	Assert::notNull($target);
	assert($target !== null);
	Assert::same('page', $target->type);
	Assert::same(5, $target->id);
	Assert::null($repo->findTarget('en', 'o-nas'));
	Assert::null($repo->findTarget('cs', 'O-NAS'));
	Assert::null($repo->findTarget('cs', 'jine'));
});

test('record upserts', function () use ($repo, $reset, $explorer): void {
	$reset();
	$repo->record('cs', 'o-nas', new RedirectTarget('page', 5));
	$repo->record('cs', 'o-nas', new RedirectTarget('page', 6));

	Assert::same(6, $repo->findTarget('cs', 'o-nas')?->id);
	Assert::same(1, $explorer->table('path_redirect')->count('*'));
});

test('deleteByPath removes one row only', function () use ($repo, $reset): void {
	$reset();
	$repo->record('cs', 'a', new RedirectTarget('page', 1));
	$repo->record('cs', 'b', new RedirectTarget('page', 1));
	$repo->record('en', 'a', new RedirectTarget('page', 1));
	$repo->deleteByPath('cs', 'a');

	Assert::null($repo->findTarget('cs', 'a'));
	Assert::notNull($repo->findTarget('cs', 'b'));
	Assert::notNull($repo->findTarget('en', 'a'));
	$repo->deleteByPath('cs', 'missing');
});

test('deleteForTarget removes every redirect to the target', function () use ($repo, $reset): void {
	$reset();
	$repo->record('cs', 'a', new RedirectTarget('page', 1));
	$repo->record('en', 'b', new RedirectTarget('page', 1));
	$repo->record('cs', 'c', new RedirectTarget('page', 2));
	$repo->deleteForTarget(new RedirectTarget('page', 1));

	Assert::null($repo->findTarget('cs', 'a'));
	Assert::null($repo->findTarget('en', 'b'));
	Assert::notNull($repo->findTarget('cs', 'c'));
});

test('the container autowires the redirect adapter', function () use ($container): void {
	Assert::type(DatabasePathRedirectRepository::class, $container->getByType(PathRedirectRepository::class));
});

test('a redirect may target a news item; pages and news with the same id stay apart', function () use ($repo, $reset): void {
	$reset();
	$repo->record('cs', 'novinky/stary', new RedirectTarget('news', 1));
	$repo->record('cs', 'stary', new RedirectTarget('page', 1));

	Assert::same('news', $repo->findTarget('cs', 'novinky/stary')?->type);
	$repo->deleteForTarget(new RedirectTarget('news', 1));
	Assert::null($repo->findTarget('cs', 'novinky/stary'));
	Assert::same('page', $repo->findTarget('cs', 'stary')?->type);
});
