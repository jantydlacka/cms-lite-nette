<?php

declare(strict_types=1);

use App\Core\Localization\CurrentLocale;
use App\Core\Localization\MissingTranslationException;
use App\Core\Localization\NeonTranslator;
use App\Core\Localization\PluralRules;
use App\Model\CmsConfig;
use Tester\Assert;
use Tests\FakeLogger;
use Tracy\ILogger;

require __DIR__ . '/../../bootstrap.php';

function translator(bool $strict = true, ?FakeLogger $logger = null, string $dir = __DIR__ . '/fixtures'): NeonTranslator
{
	return new NeonTranslator($dir, new CurrentLocale(new CmsConfig('Demo', 'cs', ['cs'], 10)), $strict, $logger ?? new FakeLogger());
}


test('translates a plain key', function (): void {
	Assert::same('Stránka byla uložena.', translator()->translate('page.saved'));
});


test('replaces named parameters', function (): void {
	Assert::same('Ahoj, Jana!', translator()->translate('page.greeting', ['name' => 'Jana']));
});


$cases = [
	[0, '0 stránek'],
	[1, '1 stránka'],
	[2, '2 stránky'],
	[4, '4 stránky'],
	[5, '5 stránek'],
	[21, '21 stránek'],
	[22, '22 stránek'],
	[-1, '-1 stránka'],
];
foreach ($cases as [$count, $expected]) {
	test("plural for $count", function () use ($count, $expected): void {
		Assert::same($expected, translator()->translate('page.count', $count));
	});
}


test('plural rules use the absolute value', function (): void {
	Assert::same('one', PluralRules::cs(-1));
	Assert::same('few', PluralRules::cs(-3));
	Assert::same('other', PluralRules::cs(-5));
});


test('a non-integer count is rejected', function (): void {
	Assert::exception(fn () => translator()->translate('page.count', 1.5), InvalidArgumentException::class);
	Assert::exception(fn () => translator()->translate('page.count'), InvalidArgumentException::class);
});


test('a missing key throws in strict mode', function (): void {
	Assert::exception(fn () => translator(true)->translate('missing.key'), MissingTranslationException::class, '%a%missing.key%a%');
});


test('a missing key returns the key and logs a warning otherwise', function (): void {
	$logger = new FakeLogger();
	Assert::same('missing.key', translator(false, $logger)->translate('missing.key'));
	Assert::count(1, $logger->records);
	Assert::same(ILogger::WARNING, $logger->records[0][1]);
});


test('current locale defaults and validates', function (): void {
	$locale = new CurrentLocale(new CmsConfig('Demo', 'cs', ['cs'], 10));
	Assert::same('cs', $locale->get());
	Assert::exception(fn () => $locale->set('en'), InvalidArgumentException::class);

	$multi = new CurrentLocale(new CmsConfig('Demo', 'cs', ['cs', 'en'], 10));
	$multi->set('en');
	Assert::same('en', $multi->get());
});


test('switching locale uses the matching catalog and switches back', function (): void {
	$locale = new CurrentLocale(new CmsConfig('Demo', 'cs', ['cs', 'en'], 10));
	$translator = new NeonTranslator(__DIR__ . '/fixtures', $locale, true, new FakeLogger());
	Assert::same('Stránka byla uložena.', $translator->translate('page.saved'));
	$locale->set('en');
	Assert::same('Page saved.', $translator->translate('page.saved'));
	$locale->set('cs');
	Assert::same('Stránka byla uložena.', $translator->translate('page.saved'));
});


$malformed = ['scalar', 'unknown-form', 'nested', 'missing-form', 'non-string-form', 'top-level-list'];
foreach ($malformed as $case) {
	test("malformed catalog rejected: $case", function () use ($case): void {
		$pattern = $case === 'top-level-list' ? '%a%cs.neon%a%must be a map%a%' : "%a%'a.b'%a%cs.neon%a%";
		Assert::exception(fn () => translator(false, null, __DIR__ . '/fixtures/invalid/' . $case)->translate('a.b'), InvalidArgumentException::class, $pattern);
	});
}


test('a missing translation file is reported', function (): void {
	Assert::exception(fn () => translator(false, null, __DIR__ . '/fixtures/empty')->translate('x'), InvalidArgumentException::class, '%a%cs.neon%a%not found%a%');
});


foreach (['string' => 'x', 'float' => 1.5, 'bool' => true, 'object' => new stdClass()] as $type => $value) {
	test("parameter of type $type is rejected", function () use ($value): void {
		Assert::exception(fn () => translator()->translate('page.saved', $value), InvalidArgumentException::class);
		Assert::exception(fn () => translator()->translate('page.count', $value), InvalidArgumentException::class);
	});
}


test('unreplaced placeholders throw in strict mode and warn otherwise', function (): void {
	Assert::exception(fn () => translator(true)->translate('page.greeting'), InvalidArgumentException::class, 'Placeholder %a%');
	Assert::exception(fn () => translator(true)->translate('page.greeting', ['other' => 'x']), InvalidArgumentException::class, 'Placeholder %a%');

	$logger = new FakeLogger();
	Assert::same('Ahoj, %name%!', translator(false, $logger)->translate('page.greeting'));
	Assert::count(1, $logger->records);
	Assert::same(ILogger::WARNING, $logger->records[0][1]);
});


test('placeholders in parameter values are not treated as missing', function (): void {
	$logger = new FakeLogger();
	Assert::same('Ahoj, 50%off%!', translator(true, $logger)->translate('page.greeting', ['name' => '50%off%']));
	Assert::same([], $logger->records);
});


$mismatches = [
	'unused named parameter' => ['page.saved', [['x' => 'y']]],
	'unused count' => ['page.saved', [3]],
	'extra argument' => ['page.greeting', [['name' => 'Jana'], 'extra']],
];
foreach ($mismatches as $name => [$key, $args]) {
	test("parameter mismatch: $name", function () use ($key, $args): void {
		Assert::exception(fn () => translator(true)->translate($key, ...$args), InvalidArgumentException::class);

		$logger = new FakeLogger();
		translator(false, $logger)->translate($key, ...$args);
		Assert::count(1, $logger->records);
		Assert::same(ILogger::WARNING, $logger->records[0][1]);
	});
}


test('an explicit null first parameter is rejected', function (): void {
	Assert::exception(fn () => translator(true)->translate('page.saved', null), InvalidArgumentException::class);
	Assert::exception(fn () => translator(false)->translate('page.saved', null), InvalidArgumentException::class);
});


test('named values may be strings, numbers and Stringable objects', function (): void {
	$logger = new FakeLogger();
	$stringable = new class() implements Stringable {
		public function __toString(): string
		{
			return 'Eva';
		}
	};
	Assert::same('Ahoj, 7!', translator(true, $logger)->translate('page.greeting', ['name' => 7]));
	Assert::same('Ahoj, 1.5!', translator(true, $logger)->translate('page.greeting', ['name' => 1.5]));
	Assert::same('Ahoj, Eva!', translator(true, $logger)->translate('page.greeting', ['name' => $stringable]));
	Assert::same([], $logger->records);
});


foreach (['null' => null, 'array' => ['x'], 'object' => new stdClass(), 'true' => true, 'false' => false] as $type => $value) {
	test("named value of type $type throws in strict mode and warns otherwise", function () use ($value): void {
		Assert::exception(
			fn () => translator(true)->translate('page.greeting', ['name' => $value]),
			InvalidArgumentException::class,
			"%a%'name'%a%page.greeting%a%",
		);

		$logger = new FakeLogger();
		Assert::same('Ahoj, %name%!', translator(false, $logger)->translate('page.greeting', ['name' => $value]));
		Assert::count(1, $logger->records);
		Assert::same(ILogger::WARNING, $logger->records[0][1]);
	});
}
