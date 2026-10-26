<?php

declare(strict_types=1);

use App\Bootstrap;
use App\Core\SystemClock;
use Psr\Clock\ClockInterface;
use Tester\Assert;
use Tests\Support\FrozenClock;

require __DIR__ . '/../bootstrap.php';

test('system clock returns the current time', function (): void {
	$before = new DateTimeImmutable();
	$now = (new SystemClock())->now();
	$after = new DateTimeImmutable();

	Assert::true($before <= $now && $now <= $after);
});


test('container autowires the clock interface to the system clock', function (): void {
	$container = Bootstrap::bootForTests()->createContainer();

	Assert::type(SystemClock::class, $container->getByType(ClockInterface::class));
	Assert::same([$container->findByType(SystemClock::class)[0]], $container->findByType(ClockInterface::class));
});


test('frozen clock returns the set time and can be moved', function (): void {
	$clock = new FrozenClock(new DateTimeImmutable('2026-01-01 10:00:00'));
	Assert::same('2026-01-01 10:00:00', $clock->now()->format('Y-m-d H:i:s'));

	$clock->modify('+1 day');
	Assert::same('2026-01-02 10:00:00', $clock->now()->format('Y-m-d H:i:s'));

	$clock->set(new DateTimeImmutable('2030-05-05 00:00:00'));
	Assert::same('2030-05-05 00:00:00', $clock->now()->format('Y-m-d H:i:s'));
});


test('frozen clock rejects an invalid modifier and keeps its time', function (): void {
	$clock = new FrozenClock(new DateTimeImmutable('2026-01-01 10:00:00'));

	Assert::exception(fn () => $clock->modify('not a modifier'), DateMalformedStringException::class);
	Assert::same('2026-01-01 10:00:00', $clock->now()->format('Y-m-d H:i:s'));
});


test('system clock is UTC regardless of the default timezone', function (): void {
	$original = date_default_timezone_get();
	date_default_timezone_set('Europe/Prague');
	try {
		Assert::same('UTC', (new SystemClock())->now()->getTimezone()->getName());
	} finally {
		date_default_timezone_set($original);
	}
});


test('frozen clock can move back', function (): void {
	$clock = new FrozenClock(new DateTimeImmutable('2026-01-02 10:00:00'));
	$clock->modify('-1 day');

	Assert::same('2026-01-01 10:00:00', $clock->now()->format('Y-m-d H:i:s'));
});
