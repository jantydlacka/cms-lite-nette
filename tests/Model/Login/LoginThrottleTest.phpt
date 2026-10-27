<?php

declare(strict_types=1);

use App\Model\Login\Database\DatabaseLoginAttemptRepository;
use App\Model\Login\LoginAttemptRepository;
use App\Model\Login\LoginLimits;
use App\Model\Login\LoginThrottle;
use Nette\Database\Explorer;
use Tester\Assert;
use Tests\Support\FrozenClock;
use Tests\Support\TestDatabase;
use function Tests\container;

require __DIR__ . '/../../bootstrap.php';

$container = container();
$explorer = $container->getByType(Explorer::class);
$clock = new FrozenClock(new DateTimeImmutable('2026-01-15 12:00:00', new DateTimeZone('UTC')));
$repo = new DatabaseLoginAttemptRepository($explorer);
$throttle = new LoginThrottle($repo, $clock, new LoginLimits(5, 20, 15));
$rows = static function () use ($explorer): int {
	$count = $explorer->query('SELECT COUNT(*) FROM login_attempt')->fetchField();
	assert(is_int($count) || is_string($count));

	return (int) $count;
};
$reset = static function () use ($container, $clock): void {
	TestDatabase::prepare($container);
	$clock->set(new DateTimeImmutable('2026-01-15 12:00:00', new DateTimeZone('UTC')));
};

test('the sixth attempt of a pair is blocked and leaves no row', function () use ($throttle, $reset, $rows): void {
	$reset();
	for ($i = 1; $i <= 5; $i++) {
		Assert::true($throttle->attempt('a@x.cz', '1.1.1.1'), "attempt $i");
	}
	Assert::false($throttle->attempt('a@x.cz', '1.1.1.1'));
	Assert::same(5, $rows());
	Assert::false($throttle->attempt('a@x.cz', '1.1.1.1'));
	Assert::same(5, $rows());
});

test('another IP for the same e-mail is not blocked', function () use ($throttle, $reset): void {
	$reset();
	for ($i = 0; $i < 6; $i++) {
		$throttle->attempt('a@x.cz', '1.1.1.1');
	}
	Assert::true($throttle->attempt('a@x.cz', '2.2.2.2'));
});

test('e-mail is normalized before counting', function () use ($throttle, $reset): void {
	$reset();
	for ($i = 0; $i < 3; $i++) {
		$throttle->attempt(' A@x.cz ', '1.1.1.1');
		$throttle->attempt('a@x.cz', '1.1.1.1');
	}
	Assert::false($throttle->attempt('A@X.cz', '1.1.1.1'));
});

test('attempt records the failure before it counts', function () use ($clock): void {
	$calls = [];
	$spy = new class($calls) implements LoginAttemptRepository {
		/** @param list<string> $calls */
		public function __construct(public array &$calls)
		{
		}

		public function recordFailure(string $email, string $ip, DateTimeImmutable $at): int
		{
			$this->calls[] = 'record';

			return 1;
		}

		public function countForPair(string $email, string $ip, DateTimeImmutable $since): int
		{
			$this->calls[] = 'pair';

			return 1;
		}

		public function countForIp(string $ip, DateTimeImmutable $since): int
		{
			$this->calls[] = 'ip';

			return 1;
		}

		public function removeFailure(int $id): void
		{
		}

		public function clearPair(string $email, string $ip): void
		{
		}

		public function deleteOlderThan(DateTimeImmutable $before): int
		{
			return 0;
		}
	};
	Assert::true((new LoginThrottle($spy, $clock, new LoginLimits(5, 20, 15)))->attempt('a@x.cz', '1.1.1.1'));

	Assert::same('record', $calls[0]);
	Assert::contains('pair', $calls);
	Assert::contains('ip', $calls);
});

test('IP key: IPv6 addresses of one /64 are summed, others are not', function () use ($repo, $clock, $reset): void {
	$reset();
	$limited = new LoginThrottle($repo, $clock, new LoginLimits(5, 2, 15));
	Assert::true($limited->attempt('a@x.cz', '2001:db8:1:2::1'));
	Assert::true($limited->attempt('b@x.cz', '2001:db8:1:2:ffff::9'));
	Assert::false($limited->attempt('c@x.cz', '2001:DB8:1:2:0:0:0:5'));
	Assert::true($limited->attempt('d@x.cz', '2001:db8:1:3::1'));
});

test('IP key: IPv4-mapped IPv6 equals the IPv4 address', function () use ($throttle, $reset): void {
	$reset();
	for ($i = 0; $i < 3; $i++) {
		$throttle->attempt('a@x.cz', '::ffff:10.0.0.1');
		$throttle->attempt('a@x.cz', '10.0.0.1');
	}
	Assert::false($throttle->attempt('a@x.cz', '10.0.0.1'));
});

test('after the window the pair is allowed again', function () use ($throttle, $reset, $clock): void {
	$reset();
	for ($i = 0; $i < 6; $i++) {
		$throttle->attempt('a@x.cz', '1.1.1.1');
	}
	$clock->modify('+15 minutes +1 second');

	Assert::true($throttle->attempt('a@x.cz', '1.1.1.1'));
});

test('a failure exactly at the window boundary still counts', function () use ($throttle, $reset, $clock): void {
	$reset();
	for ($i = 0; $i < 5; $i++) {
		$throttle->attempt('a@x.cz', '1.1.1.1');
	}
	$clock->modify('+15 minutes');

	Assert::false($throttle->attempt('a@x.cz', '1.1.1.1'));
});

test('twenty failures from one IP on different e-mails block the next e-mail', function () use ($throttle, $reset): void {
	$reset();
	for ($i = 1; $i <= 19; $i++) {
		Assert::true($throttle->attempt("u$i@x.cz", '1.1.1.1'), "attempt $i");
	}
	Assert::true($throttle->attempt('u20@x.cz', '1.1.1.1'));
	Assert::false($throttle->attempt('u21@x.cz', '1.1.1.1'));
	Assert::true($throttle->attempt('u21@x.cz', '2.2.2.2'));
});

test('registerSuccess clears only its own pair', function () use ($throttle, $reset, $rows): void {
	$reset();
	$throttle->attempt('a@x.cz', '1.1.1.1');
	$throttle->attempt('a@x.cz', '1.1.1.1');
	$throttle->attempt('a@x.cz', '2.2.2.2');
	$throttle->attempt('b@x.cz', '1.1.1.1');
	$throttle->registerSuccess(' A@x.cz', '1.1.1.1');

	Assert::same(2, $rows());
});

test('attempt deletes records older than the window', function () use ($throttle, $reset, $rows, $clock): void {
	$reset();
	$throttle->attempt('a@x.cz', '1.1.1.1');
	$throttle->attempt('b@x.cz', '2.2.2.2');
	$clock->modify('+16 minutes');
	$throttle->attempt('c@x.cz', '3.3.3.3');

	Assert::same(1, $rows());
});

test('an e-mail over 254 characters does not crash and shares one bucket', function () use ($throttle, $reset, $rows): void {
	$reset();
	$long = str_repeat('a', 300) . '@x.cz';
	for ($i = 1; $i <= 5; $i++) {
		Assert::true($throttle->attempt($long, '1.1.1.1'), "attempt $i");
	}
	Assert::same(5, $rows());
	Assert::false($throttle->attempt($long . 'b', '1.1.1.1'));
	Assert::same(5, $rows());
});

test('IP key: zone id is stripped', function () use ($throttle, $reset): void {
	$reset();
	for ($i = 0; $i < 3; $i++) {
		$throttle->attempt('a@x.cz', 'fe80::1%eth0');
		$throttle->attempt('a@x.cz', 'fe80::2');
	}
	Assert::false($throttle->attempt('a@x.cz', 'fe80::3%lo'));
});

test('IP key: unparsable, empty and non-ASCII values become "unknown"', function () use ($throttle, $reset, $explorer): void {
	$reset();
	foreach (['', 'zzz', "Å¾", 'x%y'] as $ip) {
		Assert::true($throttle->attempt('a@x.cz', $ip));
	}
	Assert::same(['unknown'], $explorer->query('SELECT DISTINCT ip FROM login_attempt')->fetchPairs(null, 'ip'));
	Assert::true($throttle->attempt('a@x.cz', 'unknown'));
	Assert::false($throttle->attempt('a@x.cz', ''));
});

test('a block by the IP limit removes the blocked attempt row', function () use ($repo, $clock, $reset, $rows): void {
	$reset();
	$limited = new LoginThrottle($repo, $clock, new LoginLimits(5, 2, 15));
	$limited->attempt('a@x.cz', '1.1.1.1');
	$limited->attempt('b@x.cz', '1.1.1.1');
	Assert::false($limited->attempt('c@x.cz', '1.1.1.1'));

	Assert::same(2, $rows());
});

test('registerSuccess maps the IP like attempt does', function () use ($throttle, $reset, $rows): void {
	$reset();
	$throttle->attempt('a@x.cz', '2001:db8:1:2::1');
	$throttle->attempt('a@x.cz', '::ffff:10.0.0.1');
	$throttle->attempt('a@x.cz', '10.0.0.1');
	$throttle->attempt('a@x.cz', '2001:db8:1:3::1');
	$throttle->registerSuccess('a@x.cz', '2001:db8:1:2:9::9');
	Assert::same(3, $rows());
	$throttle->registerSuccess('a@x.cz', '::ffff:10.0.0.1');
	Assert::same(1, $rows());
});
