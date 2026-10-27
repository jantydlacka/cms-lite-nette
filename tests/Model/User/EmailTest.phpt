<?php

declare(strict_types=1);

use App\Model\User\Email;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';

test('trims and lowercases', function (): void {
	Assert::same('admin@example.com', Email::normalize(' Admin@Example.COM '));
});

test('lowercases non-ASCII characters', function (): void {
	Assert::same('jan@žluť.cz', Email::normalize('Jan@Žluť.CZ'));
});

test('trims non-ASCII whitespace like the form does', function (): void {
	Assert::same('a@b.cz', Email::normalize("a@b.cz\u{A0}"));
	Assert::same('a@b.cz', Email::normalize("\u{3000}A@B.cz"));
});
