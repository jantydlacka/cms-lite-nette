<?php

declare(strict_types=1);

use App\Core\Security\NettePasswordHasher;
use App\Model\User\CreateUser;
use App\Model\User\Database\DatabaseUserRepository;
use App\Model\User\DuplicateEmailException;
use App\Model\User\InvalidUserException;
use App\Model\User\PasswordHasher;
use App\Model\User\Role;
use App\Model\User\User;
use App\Model\User\UserRepository;
use App\Model\User\UserService;
use Nette\Database\Explorer;
use Nette\Security\Passwords;
use Tester\Assert;
use Tests\Support\FrozenClock;
use Tests\Support\TestDatabase;
use function Tests\container;

require __DIR__ . '/../../bootstrap.php';

$container = container();
$explorer = $container->getByType(Explorer::class);
$clock = new FrozenClock(new DateTimeImmutable('2026-01-15 12:00:00', new DateTimeZone('UTC')));
$repo = new DatabaseUserRepository($explorer, $clock);
$hasher = new NettePasswordHasher(new Passwords(PASSWORD_BCRYPT, ['cost' => 4]));
$service = new UserService($repo, $hasher);
$reset = static fn () => TestDatabase::prepare($container);
$valid = static fn (string $email = 'admin@example.com', string $name = 'Admin', string $password = 'correct horse battery'): CreateUser => new CreateUser($email, $name, $password, Role::Admin);
$reason = static function (CreateUser $command) use ($service, $explorer): string {
	$before = $explorer->table('user')->count('*');
	$e = Assert::exception(fn () => $service->create($command), InvalidUserException::class);
	assert($e instanceof InvalidUserException);
	Assert::same($before, $explorer->table('user')->count('*'));

	return $e->reason;
};

test('a valid command creates an active user with a verifiable hash', function () use ($service, $repo, $hasher, $reset, $valid): void {
	$reset();
	$user = $service->create($valid(email: ' Admin@Example.COM '));

	Assert::same('admin@example.com', $user->email);
	Assert::same(Role::Admin, $user->role);
	Assert::true($user->active);
	Assert::equal($user, $repo->findById($user->id));
	$hash = $repo->passwordHash($user->id);
	assert($hash !== null);
	Assert::notSame('correct horse battery', $hash);
	Assert::true($hasher->verify('correct horse battery', $hash));
});

test('invalid e-mail', function () use ($reason, $reset, $valid): void {
	$reset();
	Assert::same('user.email.format', $reason($valid(email: 'neni-email')));
});

test('taken e-mail is detected after normalization', function () use ($reason, $service, $reset, $valid): void {
	$reset();
	$service->create($valid());
	Assert::same('user.email.taken', $reason($valid(email: ' ADMIN@example.com ')));
});

test('name must be non-blank and at most 100 characters', function () use ($reason, $service, $reset, $valid): void {
	$reset();
	Assert::same('user.name.empty', $reason($valid(name: '  ')));
	Assert::same('user.name.long', $reason($valid(name: str_repeat('ž', 101))));
	$service->create($valid(name: str_repeat('ž', 100)));
});

test('password length is at least 12 characters', function () use ($reason, $service, $reset, $valid): void {
	$reset();
	Assert::same('user.password.short', $reason($valid(password: str_repeat('a', 11))));
	$service->create($valid(password: str_repeat('a', 12)));
});

test('password must not start or end with whitespace, inner spaces are fine', function () use ($reason, $service, $reset, $valid): void {
	$reset();
	Assert::same('user.password.whitespace', $reason($valid(password: ' ' . str_repeat('a', 12))));
	Assert::same('user.password.whitespace', $reason($valid(password: str_repeat('a', 12) . " \t")));
	$service->create($valid(password: 'aaaaaa aaaaaa'));
});

test('password is at most 72 bytes', function () use ($reason, $service, $reset, $valid): void {
	$reset();
	Assert::same('user.password.long', $reason($valid(password: str_repeat('ž', 37))));
	$service->create($valid(password: str_repeat('a', 72)));
});

test('non-ASCII whitespace at the edges is rejected, inside it is fine', function () use ($reason, $service, $reset, $valid): void {
	$reset();
	Assert::same('user.password.whitespace', $reason($valid(password: "\u{A0}" . str_repeat('a', 12))));
	Assert::same('user.password.whitespace', $reason($valid(password: str_repeat('a', 12) . "\u{3000}")));
	$service->create($valid(password: "aaaaaa\u{A0}aaaaaa"));
});

$controls = ['NUL' => "\0", 'SOH' => "\x01", 'LF' => "\n", 'DEL' => "\x7F", 'NEL (C1)' => "\u{85}", 'APC (C1)' => "\u{9F}", 'invalid UTF-8' => "\xFF"];
foreach ($controls as $title => $char) {
	test("password with a control character or invalid UTF-8 is user.password.chars: $title", function () use ($reason, $reset, $valid, $char): void {
		$reset();
		Assert::same('user.password.chars', $reason($valid(password: 'aaaaaa' . $char . 'aaaaaa')));
		Assert::same('user.password.chars', $reason($valid(password: str_repeat('a', 12) . $char)));
	});
}

test('a tab inside the password is fine', function () use ($service, $reset, $valid): void {
	$reset();
	$service->create($valid(password: "aaaaaa\taaaaaa"));
});

test('a race on the unique key becomes user.email.taken', function () use ($hasher): void {
	$racing = new class() implements UserRepository {
		public function findById(int $id): ?User
		{
			return null;
		}


		public function findByEmail(string $email): ?User
		{
			return null;
		}


		public function passwordHash(int $id): ?string
		{
			return null;
		}


		public function insert(string $email, string $passwordHash, string $name, Role $role): int
		{
			throw new DuplicateEmailException();
		}


		public function updatePasswordHash(int $id, string $passwordHash): void
		{
		}
	};
	$e = Assert::exception(fn () => (new UserService($racing, $hasher))->create(new CreateUser('a@example.com', 'Ann', 'correct horse battery', Role::Editor)), InvalidUserException::class);
	assert($e instanceof InvalidUserException);
	Assert::same('user.email.taken', $e->reason);
});

test('a non-ASCII domain is stored as punycode and the name is trimmed', function () use ($service, $repo, $reset, $valid): void {
	$reset();
	$user = $service->create($valid(email: 'jan@žluť.cz', name: ' Ann '));

	Assert::same('jan@xn--lu-yta1h.cz', $user->email);
	Assert::same($user->id, $repo->findByEmail('jan@xn--lu-yta1h.cz')?->id);
	Assert::same('Ann', $user->name);
});

test('a non-ASCII local part and an unconvertible domain are user.email.format', function () use ($reason, $reset, $valid): void {
	$reset();
	Assert::same('user.email.format', $reason($valid(email: 'žan@example.com')));
	Assert::same('user.email.format', $reason($valid(email: 'a@ž' . str_repeat('a', 63) . '.cz')));
	Assert::same('user.email.format', $reason($valid(email: 'a@' . str_repeat('a', 64) . '.cz')));
});

test('e-mail length boundary is 254 bytes', function () use ($reason, $service, $reset, $valid): void {
	$reset();
	$domain = '@' . str_repeat('b', 60) . '.' . str_repeat('c', 60) . '.' . str_repeat('d', 60) . '.com';
	$make = static fn (int $total): string => str_repeat('a', $total - strlen($domain)) . $domain;
	$service->create($valid(email: 'x' . substr($make(254), 1)));
	Assert::same('user.email.format', $reason($valid(email: $make(255))));
});

test('the container wires UserService and the hasher', function () use ($container, $reset): void {
	$reset();
	Assert::type(UserService::class, $container->getByType(UserService::class));
	$hasher = $container->getByType(PasswordHasher::class);
	Assert::type(NettePasswordHasher::class, $hasher);

	$user = $container->getByType(UserService::class)->create(new CreateUser('c@example.com', 'Cee', 'correct horse battery', Role::Editor));
	$hash = $container->getByType(UserRepository::class)->passwordHash($user->id);
	assert($hash !== null);
	Assert::true($hasher->verify('correct horse battery', $hash));
});

test('e-mail and name are trimmed of non-ASCII whitespace before storing', function () use ($service, $reason, $reset, $valid): void {
	$reset();
	$user = $service->create($valid(email: "a@b.cz\u{A0}", name: "\u{A0}Ann\u{3000}"));

	Assert::same('a@b.cz', $user->email);
	Assert::same('Ann', $user->name);
	Assert::same('user.name.empty', $reason($valid(email: 'x@b.cz', name: "\u{A0}\u{A0}")));
});
