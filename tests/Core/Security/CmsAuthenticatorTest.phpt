<?php

declare(strict_types=1);

use App\Core\Security\CmsAuthenticator;
use App\Core\Security\NettePasswordHasher;
use App\Model\Login\Database\DatabaseLoginAttemptRepository;
use App\Model\Login\LoginLimits;
use App\Model\Login\LoginThrottle;
use App\Model\User\Database\DatabaseUserRepository;
use App\Model\User\PasswordHasher;
use App\Model\User\Role;
use Nette\Database\Explorer;
use Nette\Http\Request;
use Nette\Http\UrlScript;
use Nette\Security\AuthenticationException;
use Nette\Security\Authorizator;
use Nette\Security\IIdentity;
use Nette\Security\Passwords;
use Nette\Security\Permission;
use Nette\Security\SimpleIdentity;
use Nette\Security\User;
use Nette\Security\UserStorage;
use Tester\Assert;
use Tests\Support\FrozenClock;
use Tests\Support\TestDatabase;
use function Tests\container;

require __DIR__ . '/../../bootstrap.php';

$container = container();
$explorer = $container->getByType(Explorer::class);
$clock = new FrozenClock(new DateTimeImmutable('2026-01-15 12:00:00', new DateTimeZone('UTC')));
$users = new DatabaseUserRepository($explorer, $clock);
$passwords = new Passwords(PASSWORD_BCRYPT, ['cost' => 4]);
$hasher = new class(new NettePasswordHasher($passwords)) implements PasswordHasher {
	public int $verifyCalls = 0;


	public function __construct(private PasswordHasher $inner)
	{
	}


	public function hash(string $password): string
	{
		return $this->inner->hash($password);
	}


	public function verify(string $password, string $hash): bool
	{
		$this->verifyCalls++;

		return $this->inner->verify($password, $hash);
	}


	public function needsRehash(string $hash): bool
	{
		return $this->inner->needsRehash($hash);
	}
};
$dummyHash = password_hash('unused', PASSWORD_BCRYPT, ['cost' => 4]);
$throttle = new LoginThrottle(new DatabaseLoginAttemptRepository($explorer), $clock, new LoginLimits(5, 20, 15));
$makeAuth = static fn (?string $ip = '1.1.1.1'): CmsAuthenticator => new CmsAuthenticator(
	$users,
	$hasher,
	$throttle,
	new Request(new UrlScript('http://localhost/'), remoteAddress: $ip),
	$dummyHash,
);
$auth = $makeAuth();
$attempts = static function () use ($explorer): int {
	$count = $explorer->query('SELECT COUNT(*) FROM login_attempt')->fetchField();
	assert(is_int($count) || is_string($count));

	return (int) $count;
};
$reset = static function () use ($container, $clock, $users, $hasher): int {
	TestDatabase::prepare($container);
	$clock->set(new DateTimeImmutable('2026-01-15 12:00:00', new DateTimeZone('UTC')));
	$hasher->verifyCalls = 0;
	return $users->insert('admin@example.com', $hasher->hash('correct horse battery'), 'Ann Admin', Role::Admin);
};
$code = static function (CmsAuthenticator $auth, string $email, string $password): int {
	$e = Assert::exception(fn () => $auth->authenticate($email, $password), AuthenticationException::class);
	assert($e instanceof AuthenticationException);

	return $e->getCode();
};

test('valid credentials give an identity with id, role and profile data', function () use ($auth, $reset): void {
	$id = $reset();
	$identity = $auth->authenticate('admin@example.com', 'correct horse battery');
	Assert::same($id, $identity->getId());
	Assert::same(['admin'], $identity->getRoles());
	Assert::same('Ann Admin', $identity->getData()['name']);
	Assert::same('admin@example.com', $identity->getData()['email']);
});

test('the e-mail is trimmed and lower-cased', function () use ($auth, $reset): void {
	$id = $reset();
	Assert::same($id, $auth->authenticate(' ADMIN@example.com ', 'correct horse battery')->getId());
});

test('wrong password, unknown e-mail and inactive account fail alike and are recorded', function () use ($auth, $reset, $code, $attempts, $explorer): void {
	$reset();
	Assert::same(CmsAuthenticator::InvalidCredential, $code($auth, 'admin@example.com', 'wrong password!!'));
	Assert::same(1, $attempts());
	Assert::same(CmsAuthenticator::InvalidCredential, $code($auth, 'nobody@example.com', 'correct horse battery'));
	Assert::same(2, $attempts());

	$explorer->query('UPDATE user SET active = 0');
	Assert::same(CmsAuthenticator::InvalidCredential, $code($auth, 'admin@example.com', 'correct horse battery'));
	Assert::same(3, $attempts());
});

test('an unknown account still costs one password verification', function () use ($auth, $reset, $code, $hasher): void {
	$reset();
	$code($auth, 'nobody@example.com', 'whatever whatever');
	Assert::same(1, $hasher->verifyCalls);
});

test('an inactive account still costs one password verification', function () use ($auth, $reset, $code, $hasher, $explorer): void {
	$reset();
	$explorer->query('UPDATE user SET active = 0');
	$code($auth, 'admin@example.com', 'correct horse battery');
	Assert::same(1, $hasher->verifyCalls);
});

test('the compiled dummy hash matches the configured hasher, or unknown accounts would answer faster', function () use ($container): void {
	Assert::false($container->getByType(PasswordHasher::class)->needsRehash($container->getByType(CmsAuthenticator::class)->dummyHash()));
});

test('a password longer than 72 bytes is rejected even if its first 72 bytes match', function () use ($auth, $reset, $code, $users, $hasher): void {
	$id = $reset();
	$users->updatePasswordHash($id, $hasher->hash(str_repeat('a', 72)));
	Assert::same($id, $auth->authenticate('admin@example.com', str_repeat('a', 72))->getId());
	Assert::same(CmsAuthenticator::InvalidCredential, $code($auth, 'admin@example.com', str_repeat('a', 73)));
});

test('a failed sign-in leaves the stored hash unchanged even when it would need a rehash', function () use ($reset, $code, $users, $explorer, $dummyHash): void {
	$id = $reset();
	$weak = password_hash('correct horse battery', PASSWORD_BCRYPT, ['cost' => 4]);
	$users->updatePasswordHash($id, $weak);
	$stronger = new CmsAuthenticator(
		$users,
		new NettePasswordHasher(new Passwords(PASSWORD_BCRYPT, ['cost' => 5])),
		new LoginThrottle(new DatabaseLoginAttemptRepository($explorer), new FrozenClock(new DateTimeImmutable('2026-01-15 12:00:00', new DateTimeZone('UTC'))), new LoginLimits(5, 20, 15)),
		new Request(new UrlScript('http://localhost/'), remoteAddress: '1.1.1.1'),
		$dummyHash,
	);
	$code($stronger, 'admin@example.com', 'wrong password!!');
	Assert::same($weak, $users->passwordHash($id));
	$explorer->query('UPDATE user SET active = 0');
	$code($stronger, 'admin@example.com', 'correct horse battery');
	Assert::same($weak, $users->passwordHash($id));
});

test('wakeupIdentity returns null for a non-numeric id', function () use ($auth): void {
	Assert::null($auth->wakeupIdentity(new SimpleIdentity('abc')));
	Assert::null($auth->wakeupIdentity(new SimpleIdentity('')));
});

test('after five failures even the correct password is throttled, without verifying', function () use ($auth, $reset, $code, $hasher, $attempts, $makeAuth): void {
	$id = $reset();
	for ($i = 0; $i < 5; $i++) {
		Assert::same(CmsAuthenticator::InvalidCredential, $code($auth, 'admin@example.com', 'wrong password!!'));
	}
	$hasher->verifyCalls = 0;
	Assert::same(CmsAuthenticator::Throttled, $code($auth, 'admin@example.com', 'correct horse battery'));
	Assert::same(0, $hasher->verifyCalls);
	Assert::same(5, $attempts());

	Assert::same($id, $makeAuth('2.2.2.2')->authenticate('admin@example.com', 'correct horse battery')->getId());
});

test('success clears the failures of the pair', function () use ($auth, $reset, $code, $attempts, $makeAuth): void {
	$reset();
	$code($auth, 'admin@example.com', 'wrong password!!');
	$code($makeAuth('2.2.2.2'), 'admin@example.com', 'wrong password!!');
	Assert::same(2, $attempts());
	$auth->authenticate('admin@example.com', 'correct horse battery');
	Assert::same(1, $attempts());
});

test('a missing remote address is throttled under one shared key', function () use ($reset, $code, $makeAuth): void {
	$reset();
	$auth = $makeAuth(null);
	for ($i = 0; $i < 5; $i++) {
		$code($auth, 'admin@example.com', 'wrong password!!');
	}
	Assert::same(CmsAuthenticator::Throttled, $code($auth, 'admin@example.com', 'correct horse battery'));
});

test('a hash that needs a rehash is replaced on sign-in', function () use ($reset, $users, $explorer, $dummyHash): void {
	$id = $reset();
	$weak = password_hash('correct horse battery', PASSWORD_BCRYPT, ['cost' => 4]);
	$stronger = new CmsAuthenticator(
		$users,
		new NettePasswordHasher(new Passwords(PASSWORD_BCRYPT, ['cost' => 5])),
		new LoginThrottle(new DatabaseLoginAttemptRepository($explorer), new FrozenClock(new DateTimeImmutable('2026-01-15 12:00:00', new DateTimeZone('UTC'))), new LoginLimits(5, 20, 15)),
		new Request(new UrlScript('http://localhost/'), remoteAddress: '1.1.1.1'),
		$dummyHash,
	);
	$users->updatePasswordHash($id, $weak);
	$stronger->authenticate('admin@example.com', 'correct horse battery');
	$new = (string) $users->passwordHash($id);
	Assert::notSame($weak, $new);
	Assert::true(password_verify('correct horse battery', $new));
	Assert::same(5, password_get_info($new)['options']['cost']);

	// Same cost: the hash is left alone.
	$users->updatePasswordHash($id, $new);
	$stronger->authenticate('admin@example.com', 'correct horse battery');
	Assert::same($new, $users->passwordHash($id));
});

test('sleepIdentity keeps only the id', function () use ($auth, $reset): void {
	$id = $reset();
	$asleep = $auth->sleepIdentity($auth->authenticate('admin@example.com', 'correct horse battery'));
	Assert::same($id, $asleep->getId());
	Assert::same([], $asleep->getRoles());
	Assert::same([], $asleep->getData());
});

test('wakeupIdentity loads the current role and profile', function () use ($auth, $reset, $explorer): void {
	$id = $reset();
	$explorer->query('UPDATE user SET role = ?, name = ? WHERE id = ?', 'editor', 'Renamed', $id);
	$woken = $auth->wakeupIdentity(new SimpleIdentity($id, ['admin']));
	assert($woken !== null);
	Assert::same(['editor'], $woken->getRoles());
	Assert::same('Renamed', $woken->getData()['name']);
});

test('wakeupIdentity drops inactive and deleted accounts', function () use ($auth, $reset, $explorer): void {
	$id = $reset();
	$explorer->query('UPDATE user SET active = 0 WHERE id = ?', $id);
	Assert::null($auth->wakeupIdentity(new SimpleIdentity($id)));
	$explorer->query('DELETE FROM user WHERE id = ?', $id);
	Assert::null($auth->wakeupIdentity(new SimpleIdentity($id)));
});

test('the container wires CmsAuthenticator and the ACL into Nette\Security\User', function () use ($container): void {
	$user = $container->getByType(User::class);
	Assert::type(CmsAuthenticator::class, $user->getAuthenticator());
	Assert::type(Permission::class, $user->getAuthorizator());
});

$memoryStorage = static fn (): UserStorage => new class() implements UserStorage {
	public ?IIdentity $identity = null;

	public bool $authenticated = false;


	public function saveAuthentication(IIdentity $identity): void
	{
		$this->identity = $identity;
		$this->authenticated = true;
	}


	public function clearAuthentication(bool $clearIdentity): void
	{
		$this->authenticated = false;
		$this->identity = $clearIdentity ? null : $this->identity;
	}


	/** @return array{bool, ?IIdentity, ?int} */
	public function getState(): array
	{
		return [$this->authenticated, $this->identity, null];
	}


	public function setExpiration(?string $expire, bool $clearIdentity): void
	{
	}
};
$containerUser = static fn (UserStorage $storage): User => new User(
	$storage,
	$container->getByType(CmsAuthenticator::class),
	$container->getByType(Authorizator::class),
);

test('a container-built User picks up a role change on the next request', function () use ($reset, $explorer, $memoryStorage, $containerUser): void {
	$reset();
	$storage = $memoryStorage();
	$containerUser($storage)->login('admin@example.com', 'correct horse battery');
	Assert::same(['admin'], $containerUser($storage)->getRoles());
	$explorer->query('UPDATE user SET role = ?', 'editor');
	$next = $containerUser($storage);
	Assert::true($next->isLoggedIn());
	Assert::same(['editor'], $next->getRoles());
	Assert::false($next->isAllowed('users'));
	Assert::true($next->isAllowed('dashboard'));
});

test('an account deactivated or deleted after sign-in is logged out on the next request, without an exception', function () use ($reset, $explorer, $memoryStorage, $containerUser): void {
	foreach (['UPDATE user SET active = 0', 'DELETE FROM user'] as $sql) {
		$reset();
		$storage = $memoryStorage();
		$containerUser($storage)->login('admin@example.com', 'correct horse battery');
		$explorer->query($sql);
		$next = $containerUser($storage);
		Assert::false($next->isLoggedIn());
		Assert::null($next->getIdentity());
	}
});

test('logout() on a vetoed stored state does not throw and leaves no identity', function () use ($reset, $explorer, $memoryStorage, $containerUser): void {
	$reset();
	$storage = $memoryStorage();
	$containerUser($storage)->login('admin@example.com', 'correct horse battery');
	$explorer->query('DELETE FROM user');
	$next = $containerUser($storage);
	Assert::true($storage->authenticated);
	$next->logout();
	Assert::false($next->isLoggedIn());
	Assert::null($next->getIdentity());
	Assert::false($storage->authenticated);
});
