<?php

declare(strict_types=1);

use App\Model\Login\Database\DatabaseLoginAttemptRepository;
use App\Model\Login\LoginAttemptRepository;
use Nette\Database\Explorer;
use Tester\Assert;
use Tests\Support\TestDatabase;
use function Tests\container;

require __DIR__ . '/../../bootstrap.php';

$container = container();
$explorer = $container->getByType(Explorer::class);
$repo = new DatabaseLoginAttemptRepository($explorer);
$reset = static fn () => TestDatabase::prepare($container);
$utc = new DateTimeZone('UTC');
$at = static fn (string $time): DateTimeImmutable => new DateTimeImmutable($time, $utc);

test('the container autowires the database adapter for LoginAttemptRepository', function () use ($container): void {
	Assert::type(DatabaseLoginAttemptRepository::class, $container->getByType(LoginAttemptRepository::class));
});

test('counts are scoped by pair and by IP, from the boundary inclusive', function () use ($repo, $reset, $at): void {
	$reset();
	$repo->recordFailure('a@x.cz', '1.1.1.1', $at('2026-01-15 12:00:00'));
	$repo->recordFailure('a@x.cz', '1.1.1.1', $at('2026-01-15 12:05:00'));
	$repo->recordFailure('b@x.cz', '1.1.1.1', $at('2026-01-15 12:05:00'));
	$repo->recordFailure('a@x.cz', '2.2.2.2', $at('2026-01-15 12:05:00'));

	Assert::same(2, $repo->countForPair('a@x.cz', '1.1.1.1', $at('2026-01-15 12:00:00')));
	Assert::same(1, $repo->countForPair('a@x.cz', '1.1.1.1', $at('2026-01-15 12:00:01')));
	Assert::same(3, $repo->countForIp('1.1.1.1', $at('2026-01-15 12:00:00')));
	Assert::same(2, $repo->countForIp('1.1.1.1', $at('2026-01-15 12:00:01')));
	Assert::same(0, $repo->countForPair('c@x.cz', '1.1.1.1', $at('2026-01-15 11:00:00')));
});

test('times are stored in UTC regardless of the given zone', function () use ($repo, $reset, $explorer): void {
	$reset();
	$repo->recordFailure('a@x.cz', '1.1.1.1', new DateTimeImmutable('2026-01-15 13:00:00', new DateTimeZone('Europe/Prague')));

	Assert::same('2026-01-15 12:00:00', $explorer->query('SELECT DATE_FORMAT(attempted_at, ?) FROM login_attempt', '%Y-%m-%d %H:%i:%s')->fetchField());
});

test('clearPair removes only that pair', function () use ($repo, $reset, $at): void {
	$reset();
	$time = $at('2026-01-15 12:00:00');
	$repo->recordFailure('a@x.cz', '1.1.1.1', $time);
	$repo->recordFailure('a@x.cz', '2.2.2.2', $time);
	$repo->recordFailure('b@x.cz', '1.1.1.1', $time);
	$repo->clearPair('a@x.cz', '1.1.1.1');

	Assert::same(0, $repo->countForPair('a@x.cz', '1.1.1.1', $time));
	Assert::same(1, $repo->countForPair('a@x.cz', '2.2.2.2', $time));
	Assert::same(1, $repo->countForPair('b@x.cz', '1.1.1.1', $time));
});

test('deleteOlderThan removes strictly older rows and returns their count', function () use ($repo, $reset, $at): void {
	$reset();
	$repo->recordFailure('a@x.cz', '1.1.1.1', $at('2026-01-15 11:59:59'));
	$repo->recordFailure('a@x.cz', '1.1.1.1', $at('2026-01-15 12:00:00'));

	Assert::same(1, $repo->deleteOlderThan($at('2026-01-15 12:00:00')));
	Assert::same(1, $repo->countForIp('1.1.1.1', $at('2026-01-01 00:00:00')));
});

test('IPv6 prefix keys fit the column', function () use ($repo, $reset, $at): void {
	$reset();
	$key = '2001:0db8:0001:0002::/64';
	$repo->recordFailure('a@x.cz', $key, $at('2026-01-15 12:00:00'));

	Assert::same(1, $repo->countForIp($key, $at('2026-01-15 12:00:00')));
});

test('removeFailure deletes exactly the row with that id', function () use ($repo, $reset, $at): void {
	$reset();
	$time = $at('2026-01-15 12:00:00');
	$first = $repo->recordFailure('a@x.cz', '1.1.1.1', $time);
	$second = $repo->recordFailure('a@x.cz', '1.1.1.1', $time);
	Assert::notSame($first, $second);
	$repo->removeFailure($first);

	Assert::same(1, $repo->countForPair('a@x.cz', '1.1.1.1', $time));
	$repo->removeFailure($second);
	Assert::same(0, $repo->countForPair('a@x.cz', '1.1.1.1', $time));
});

test('the ip column is case-sensitive ascii', function () use ($repo, $reset, $at): void {
	$reset();
	$time = $at('2026-01-15 12:00:00');
	$repo->recordFailure('a@x.cz', 'ABC', $time);

	Assert::same(0, $repo->countForIp('abc', $time));
});
