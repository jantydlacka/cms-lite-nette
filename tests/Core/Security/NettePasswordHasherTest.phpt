<?php

declare(strict_types=1);

use App\Core\Security\NettePasswordHasher;
use Nette\Security\Passwords;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';

$hasher = new NettePasswordHasher(new Passwords(PASSWORD_BCRYPT, ['cost' => 5]));

test('hash verifies the right password and rejects a wrong one', function () use ($hasher): void {
	$hash = $hasher->hash('correct horse battery');

	Assert::notSame('correct horse battery', $hash);
	Assert::true($hasher->verify('correct horse battery', $hash));
	Assert::false($hasher->verify('wrong horse battery', $hash));
});

test('needsRehash is true for a hash with a lower cost', function () use ($hasher): void {
	$weak = (new NettePasswordHasher(new Passwords(PASSWORD_BCRYPT, ['cost' => 4])))->hash('correct horse battery');

	Assert::true($hasher->needsRehash($weak));
	Assert::false($hasher->needsRehash($hasher->hash('correct horse battery')));
});
