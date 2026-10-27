<?php

declare(strict_types=1);

use App\Core\Security\AuthorizatorFactory;
use App\Core\Security\Resource;
use Nette\InvalidStateException;
use Nette\Security\Authorizator;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';

$acl = (new AuthorizatorFactory())->create();
$allowed = static function (string $role) use ($acl): array {
	return array_values(array_map(
		static fn (Resource $r): string => $r->value,
		array_filter(Resource::cases(), static fn (Resource $r): bool => $acl->isAllowed($role, $r->value, Authorizator::All)),
	));
};

test('editor may open the dashboard, the pages and the news', function () use ($allowed): void {
	Assert::same(['dashboard', 'pages', 'news'], $allowed('editor'));
});

test('admin may open every resource', function () use ($allowed): void {
	Assert::same(['dashboard', 'pages', 'news', 'users', 'settings'], $allowed('admin'));
});

test('guest may open nothing', function () use ($allowed): void {
	Assert::same([], $allowed('guest'));
});

test('an unknown role is rejected, not silently allowed', function () use ($acl): void {
	Assert::exception(fn () => $acl->isAllowed('nobody', 'users'), InvalidStateException::class);
});
