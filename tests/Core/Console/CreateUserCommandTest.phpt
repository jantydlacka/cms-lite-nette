<?php

declare(strict_types=1);

use App\Core\Console\CreateUserCommand;
use App\Model\User\Role;
use App\Model\User\UserRepository;
use Nette\Database\Explorer;
use Symfony\Component\Console\Tester\CommandTester;
use Tester\Assert;
use Tests\Support\TestDatabase;
use function Tests\container;

require __DIR__ . '/../../bootstrap.php';

$container = container();
$explorer = $container->getByType(Explorer::class);
$users = $container->getByType(UserRepository::class);
$reset = static fn () => TestDatabase::prepare($container);
$run = static function (array $input, array $lines, bool $interactive = true) use ($container): CommandTester {
	/** @var list<string> $lines */
	$tester = new CommandTester($container->getByType(CreateUserCommand::class));
	$tester->setInputs($lines);
	$tester->execute($input, ['interactive' => $interactive, 'capture_stderr_separately' => false]);

	return $tester;
};
$count = static fn (): int => $explorer->table('user')->count('*');

test('interactive double entry creates an admin', function () use ($run, $users, $reset): void {
	$reset();
	$t = $run(['email' => ' Boss@Example.com ', 'name' => 'Boss'], ['correct horse battery', 'correct horse battery']);

	Assert::same(0, $t->getStatusCode());
	$user = $users->findByEmail('boss@example.com');
	assert($user !== null);
	Assert::same(Role::Admin, $user->role);
	Assert::contains("Created user #{$user->id} boss@example.com (admin).", $t->getDisplay());
	Assert::notContains('correct horse battery', $t->getDisplay());
});

test('--role=editor', function () use ($run, $users, $reset): void {
	$reset();
	$t = $run(['email' => 'ed@example.com', 'name' => 'Ed', '--role' => 'editor'], ['correct horse battery', 'correct horse battery']);

	Assert::same(0, $t->getStatusCode());
	Assert::same(Role::Editor, $users->findByEmail('ed@example.com')?->role);
});

test('mismatching passwords store nothing', function () use ($run, $count, $reset): void {
	$reset();
	$t = $run(['email' => 'a@example.com', 'name' => 'A'], ['correct horse battery', 'another horse battery']);

	Assert::same(1, $t->getStatusCode());
	Assert::contains('Passwords do not match.', $t->getDisplay());
	Assert::notContains('horse battery', $t->getDisplay());
	Assert::same(0, $count());
});

test('--password-stdin reads one line and strips only the line ending', function () use ($run, $users, $reset): void {
	$reset();
	$t = $run(['email' => 'a@example.com', 'name' => 'A', '--password-stdin' => true], ["correct horse battery\r\nignored\n"], false);

	Assert::same(0, $t->getStatusCode());
	Assert::notContains('correct horse battery', $t->getDisplay());
	$user = $users->findByEmail('a@example.com');
	assert($user !== null);
	Assert::true(password_verify('correct horse battery', $users->passwordHash($user->id) ?? ''));
});

foreach (['trailing space' => "correct horse battery \n", 'leading space' => " correct horse battery\n"] as $title => $line) {
	test("--password-stdin keeps a $title so the service rejects it", function () use ($run, $count, $reset, $line): void {
		$reset();
		$t = $run(['email' => 'a@example.com', 'name' => 'A', '--password-stdin' => true], [$line], false);

		Assert::same(1, $t->getStatusCode());
		Assert::contains('Password must not start or end with whitespace.', $t->getDisplay());
		Assert::same(0, $count());
	});
}

test('--password-stdin with empty input fails', function () use ($run, $count, $reset): void {
	$reset();
	$t = $run(['email' => 'a@example.com', 'name' => 'A', '--password-stdin' => true], [], false);

	Assert::same(1, $t->getStatusCode());
	Assert::contains('No password given on standard input.', $t->getDisplay());
	Assert::same(0, $count());
});

test('non-interactive run without --password-stdin says how to pass the password', function () use ($run, $count, $reset): void {
	$reset();
	$t = $run(['email' => 'a@example.com', 'name' => 'A'], [], false);

	Assert::same(1, $t->getStatusCode());
	Assert::contains('No password given; use --password-stdin when not running interactively.', $t->getDisplay());
	Assert::same(0, $count());
});

$failures = [
	'short password' => [['email' => 'a@example.com', 'name' => 'A'], 'short pw', 'Password must be at least 12 characters.'],
	'long password' => [['email' => 'a@example.com', 'name' => 'A'], str_repeat('x', 73), 'Password must be at most 72 bytes.'],
	'bad e-mail' => [['email' => 'nope', 'name' => 'A'], 'correct horse battery', 'E-mail is not valid.'],
	'empty name' => [['email' => 'a@example.com', 'name' => ' '], 'correct horse battery', 'Name must not be empty.'],
	'long name' => [['email' => 'a@example.com', 'name' => str_repeat('n', 101)], 'correct horse battery', 'Name is too long.'],
	'unknown role' => [['email' => 'a@example.com', 'name' => 'A', '--role' => 'x'], 'correct horse battery', "Unknown role 'x'."],
];
foreach ($failures as $title => [$input, $password, $message]) {
	test("failure: $title", function () use ($run, $count, $reset, $input, $password, $message): void {
		$reset();
		$t = $run($input, [$password, $password]);

		Assert::same(1, $t->getStatusCode());
		Assert::contains($message, $t->getDisplay());
		Assert::notContains($password, $t->getDisplay());
		Assert::same(0, $count());
	});
}

test('failure: duplicate e-mail', function () use ($run, $count, $reset): void {
	$reset();
	$run(['email' => 'a@example.com', 'name' => 'A'], ['correct horse battery', 'correct horse battery']);
	$t = $run(['email' => 'A@Example.com', 'name' => 'B'], ['correct horse battery', 'correct horse battery']);

	Assert::same(1, $t->getStatusCode());
	Assert::contains('A user with this e-mail already exists.', $t->getDisplay());
	Assert::same(1, $count());
});
