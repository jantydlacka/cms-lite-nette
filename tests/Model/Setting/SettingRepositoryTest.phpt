<?php

declare(strict_types=1);

use App\Model\Setting\Database\DatabaseSettingRepository;
use App\Model\Setting\SettingRepository;
use Nette\Database\Explorer;
use Tester\Assert;
use Tests\Support\TestDatabase;
use function Tests\container;

require __DIR__ . '/../../bootstrap.php';

$container = container();
$repo = new DatabaseSettingRepository($container->getByType(Explorer::class));
$reset = static fn () => TestDatabase::prepare($container);

test('unknown key is null', function () use ($repo, $reset): void {
	$reset();
	Assert::null($repo->get('missing'));
});

test('set then get', function () use ($repo, $reset): void {
	$reset();
	$repo->set('homepage', '7');
	Assert::same('7', $repo->get('homepage'));
});

test('empty locale and a named locale are separate rows', function () use ($repo, $reset): void {
	$reset();
	$repo->set('site.title', 'Global');
	$repo->set('site.title', 'Cesky', 'cs');
	Assert::same('Global', $repo->get('site.title'));
	Assert::same('Cesky', $repo->get('site.title', 'cs'));
	Assert::null($repo->get('site.title', 'en'));
});

test('set overwrites the existing value', function () use ($repo, $reset): void {
	$reset();
	$repo->set('homepage', '1');
	$repo->set('homepage', '2');
	Assert::same('2', $repo->get('homepage'));
});

test('an empty string value is stored, not treated as unset', function () use ($repo, $reset): void {
	$reset();
	$repo->set('k', '');
	Assert::same('', $repo->get('k'));
});

test('the value column rejects null', function () use ($container, $reset): void {
	$reset();
	$connection = $container->getByType(Explorer::class)->getConnection();
	Assert::same('NO', $connection->fetchField(
		'SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
		'setting',
		'value',
	));
});

test('the container autowires the database adapter for SettingRepository', function () use ($container): void {
	Assert::type(DatabaseSettingRepository::class, $container->getByType(SettingRepository::class));
});
