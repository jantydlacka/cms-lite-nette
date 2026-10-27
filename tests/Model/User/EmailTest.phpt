<?php

declare(strict_types=1);

use App\Model\User\Email;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';

test('trims and lowercases', function (): void {
	Assert::same('admin@example.com', Email::normalize(' Admin@Example.COM '));
});

test('a non-ASCII domain becomes punycode, as a browser sends it', function (): void {
	Assert::same('jan@xn--lu-yta1h.cz', Email::normalize('Jan@Žluť.CZ'));
	Assert::same('jan@xn--lu-yta1h.cz', Email::normalize('jan@xn--lu-yta1h.cz'));
});

// Measured in Chromium on /admin/sign-in (input type=email).
$browser = [
	'a@straße.de' => 'a@strasse.de',
	'a@faß.de' => 'a@fass.de',
	'A@FAẞ.de' => 'a@fass.de',
	'a@σας.gr' => 'a@xn--mxa9ab.gr',
	"a@x\u{200D}y.com" => 'a@xy.com',
	"a@x\u{200C}y.com" => 'a@xy.com',
];
foreach ($browser as $input => $expected) {
	test("the domain uses the transitional mapping of the browser: $expected", function () use ($input, $expected): void {
		Assert::same($expected, Email::normalize($input));
	});
}

test('the local part is only lowercased', function (): void {
	Assert::same('žan@example.com', Email::normalize('Žan@example.com'));
});

test('a domain that cannot be converted is kept lowercased, without an exception', function (): void {
	$domain = 'ž' . str_repeat('a', 63) . '.cz';
	Assert::same('a@' . $domain, Email::normalize(' A@' . $domain));
	Assert::same('a@', Email::normalize('a@'));
	Assert::same('no-at-sign', Email::normalize('No-At-Sign'));
});

test('trims non-ASCII whitespace like the form does', function (): void {
	Assert::same('a@b.cz', Email::normalize("a@b.cz\u{A0}"));
	Assert::same('a@b.cz', Email::normalize("\u{3000}A@B.cz"));
});

test('isValid accepts only an ASCII address within 254 bytes', function (): void {
	Assert::true(Email::isValid('jan@xn--lu-yta1h.cz'));
	Assert::false(Email::isValid('jan@žluť.cz'));
	Assert::false(Email::isValid('žan@example.com'));
	Assert::false(Email::isValid('neni-email'));
	Assert::false(Email::isValid(''));
	Assert::false(Email::isValid(str_repeat('a', 243) . '@example.com'));
});
