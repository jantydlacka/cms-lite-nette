<?php

declare(strict_types=1);

use App\Model\InvalidSlugException;
use App\Model\Slug;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';

test('fromTitle webalizes the title', function (): void {
	Assert::same('zlutoucky-kun', Slug::fromTitle('Žluťoučký kůň'));
});

test('a long title is cut at a hyphen boundary within the limit', function (): void {
	$slug = Slug::fromTitle(implode(' ', array_fill(0, 30, 'slovo')));
	Assert::true(strlen($slug) <= Slug::MAX_LENGTH);
	Assert::false(str_ends_with($slug, '-'));
	Assert::same(95, strlen($slug));
});

test('a single long word is cut at the limit', function (): void {
	Assert::same(100, strlen(Slug::fromTitle(str_repeat('a', 150))));
});

test('assertValid accepts a well-formed slug', function (): void {
	Slug::assertValid('a-b-1');
	Assert::true(true);
});

test('assertValid reports format and length', function (): void {
	$format = Assert::exception(fn () => Slug::assertValid('a--b'), InvalidSlugException::class);
	assert($format instanceof InvalidSlugException);
	Assert::same(InvalidSlugException::FORMAT, $format->reason);

	$long = Assert::exception(fn () => Slug::assertValid(str_repeat('a', 101)), InvalidSlugException::class);
	assert($long instanceof InvalidSlugException);
	Assert::same(InvalidSlugException::LONG, $long->reason);
});

test('a slug both too long and malformed is reported as too long', function (): void {
	$e = Assert::exception(fn () => Slug::assertValid(str_repeat('A-', 60)), InvalidSlugException::class);
	assert($e instanceof InvalidSlugException);
	Assert::same(InvalidSlugException::LONG, $e->reason);
});
