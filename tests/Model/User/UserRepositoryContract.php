<?php

declare(strict_types=1);

namespace Tests\Model\User;

use App\Model\User\DuplicateEmailException;
use App\Model\User\Role;
use App\Model\User\UserRepository;
use Tester\Assert;

use function test;

/** Behaviour every UserRepository implementation must share. */
final class UserRepositoryContract
{
	/** @param callable(): void $reset empties the store between scenarios */
	public static function run(UserRepository $repo, callable $reset): void
	{
		test('an inserted user is found by id and by e-mail, active', function () use ($repo, $reset): void {
			$reset();
			$id = $repo->insert('a@example.com', 'hash', 'Ann', Role::Editor);

			foreach ([$repo->findById($id), $repo->findByEmail('a@example.com')] as $user) {
				Assert::notNull($user);
				assert($user !== null);
				Assert::same($id, $user->id);
				Assert::same('a@example.com', $user->email);
				Assert::same('Ann', $user->name);
				Assert::same(Role::Editor, $user->role);
				Assert::true($user->active);
			}
			Assert::same('hash', $repo->passwordHash($id));
		});

		test('a second insert of the same e-mail throws DuplicateEmailException', function () use ($repo, $reset): void {
			$reset();
			$repo->insert('a@example.com', 'hash', 'Ann', Role::Editor);

			Assert::exception(fn () => $repo->insert('a@example.com', 'other', 'Bob', Role::Admin), DuplicateEmailException::class);
		});

		test('updatePasswordHash replaces the hash', function () use ($repo, $reset): void {
			$reset();
			$id = $repo->insert('a@example.com', 'old', 'Ann', Role::Admin);
			$repo->updatePasswordHash($id, 'new');

			Assert::same('new', $repo->passwordHash($id));
		});

		test('unknown id or e-mail yields null', function () use ($repo, $reset): void {
			$reset();
			Assert::null($repo->findById(999));
			Assert::null($repo->findByEmail('nobody@example.com'));
			Assert::null($repo->passwordHash(999));
		});
	}
}
