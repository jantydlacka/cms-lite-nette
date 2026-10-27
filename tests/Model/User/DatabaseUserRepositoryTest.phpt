<?php

declare(strict_types=1);

use App\Model\User\Database\DatabaseUserRepository;
use App\Model\User\Role;
use App\Model\User\UserRepository;
use Nette\Database\Explorer;
use Tester\Assert;
use Tests\Model\User\UserRepositoryContract;
use Tests\Support\FrozenClock;
use Tests\Support\TestDatabase;
use function Tests\container;

require __DIR__ . '/../../bootstrap.php';
require __DIR__ . '/UserRepositoryContract.php';

$container = container();
$clock = new FrozenClock(new DateTimeImmutable('2026-01-15 12:00:00', new DateTimeZone('UTC')));
$repo = new DatabaseUserRepository($container->getByType(Explorer::class), $clock);
$reset = static fn () => TestDatabase::prepare($container);

UserRepositoryContract::run($repo, $reset);

test('the container autowires the database adapter for UserRepository', function () use ($container): void {
	Assert::type(DatabaseUserRepository::class, $container->getByType(UserRepository::class));
});

test('e-mails whose punycode domains differ only in diacritics are two accounts', function () use ($repo, $reset): void {
	$reset();
	// žluť.cz and žlut.cz, as Email::normalize stores them.
	$plain = $repo->insert('a@xn--lut-b3a.cz', 'h1', 'Plain', Role::Editor);
	$accented = $repo->insert('a@xn--lu-yta1h.cz', 'h2', 'Accented', Role::Editor);

	Assert::notSame($plain, $accented);
	Assert::same($plain, $repo->findByEmail('a@xn--lut-b3a.cz')?->id);
	Assert::same($accented, $repo->findByEmail('a@xn--lu-yta1h.cz')?->id);
});

test('created_at is stored in UTC', function () use ($repo, $reset, $container): void {
	$reset();
	$repo->insert('a@example.com', 'h', 'Ann', Role::Editor);

	Assert::same('2026-01-15 12:00:00', $container->getByType(Explorer::class)->query('SELECT DATE_FORMAT(created_at, ?) FROM `user`', '%Y-%m-%d %H:%i:%s')->fetchField());
});

test('an inactive user is read with active false', function () use ($repo, $reset, $container): void {
	$reset();
	$id = $repo->insert('a@example.com', 'h', 'Ann', Role::Editor);
	$container->getByType(Explorer::class)->query('UPDATE `user` SET active = 0 WHERE id = ?', $id);

	Assert::false($repo->findById($id)?->active);
	Assert::false($repo->findByEmail('a@example.com')?->active);
});
