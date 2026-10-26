<?php

declare(strict_types=1);

use App\Core\Localization\CurrentLocale;
use App\Core\Localization\MissingTranslationException;
use App\Core\Localization\NeonTranslator;
use App\Core\Localization\PluralRules;
use App\Model\CmsConfig;
use Latte\CompileException;
use Latte\Essential\TranslatorExtension;
use Latte\Loaders\StringLoader;
use Nette\Bridges\ApplicationLatte\LatteFactory;
use Tester\Assert;
use Tests\FakeLogger;
use Tracy\ILogger;
use function Tests\container;

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
	Assert::same('one', PluralRules::form('cs', -1));
	Assert::same('few', PluralRules::form('cs', -3));
	Assert::same('other', PluralRules::form('cs', -5));
	Assert::same('one', PluralRules::form('en', -1));
	Assert::same('other', PluralRules::form('en', -3));
});


test('plural forms are per language', function (): void {
	Assert::same(['one', 'few', 'other'], PluralRules::for('cs'));
	Assert::same(['one', 'other'], PluralRules::for('en'));
	foreach ([0 => 'other', 1 => 'one', 2 => 'other', 4 => 'other', 5 => 'other', 21 => 'other'] as $n => $form) {
		Assert::same($form, PluralRules::form('en', $n));
	}
});


test('an unknown language has no plural rules', function (): void {
	Assert::exception(fn () => PluralRules::for('de'), InvalidArgumentException::class, '%a%de%a%');
	Assert::exception(fn () => PluralRules::form('de', 1), InvalidArgumentException::class, '%a%de%a%');
});


test('an English plural uses the English forms', function (): void {
	$locale = new CurrentLocale(new CmsConfig('Demo', 'cs', ['cs', 'en'], 10));
	$locale->set('en');
	$translator = new NeonTranslator(__DIR__ . '/fixtures', $locale, true, new FakeLogger());
	Assert::same('1 page', $translator->translate('page.count', 1));
	Assert::same('3 pages', $translator->translate('page.count', 3));
	Assert::same('0 pages', $translator->translate('page.count', 0));
});


test('a plural map must have exactly the forms of its language', function (): void {
	$locale = new CurrentLocale(new CmsConfig('Demo', 'cs', ['cs', 'en'], 10));
	$locale->set('en');
	$translator = new NeonTranslator(__DIR__ . '/fixtures/invalid/en-with-few', $locale, false, new FakeLogger());
	Assert::exception(fn () => $translator->translate('a.b', 1), InvalidArgumentException::class, "%a%'a.b'%a%en.neon%a%one, other%a%");
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


test('named arguments equal an array of named values', function (): void {
	Assert::same('Ahoj, Jana!', translator()->translate('page.greeting', name: 'Jana'));
	Assert::same('Ahoj, 7!', translator()->translate('page.greeting', name: 7));
});


test('an unused named argument throws in strict mode and warns otherwise', function (): void {
	Assert::exception(fn () => translator(true)->translate('page.saved', foo: 'x'), InvalidArgumentException::class, "%a%unused parameter 'foo'%a%");

	$logger = new FakeLogger();
	Assert::same('Stránka byla uložena.', translator(false, $logger)->translate('page.saved', foo: 'x'));
	Assert::count(1, $logger->records);
	Assert::same(ILogger::WARNING, $logger->records[0][1]);
});


test('a missing named argument is reported', function (): void {
	Assert::exception(fn () => translator(true)->translate('page.greeting', other: 'x'), InvalidArgumentException::class, 'Placeholder %a%');
});


test('an invalid named value is reported', function (): void {
	Assert::exception(fn () => translator(true)->translate('page.greeting', name: null), InvalidArgumentException::class, "%a%'name'%a%");
});


test('mixing a positional and named arguments is reported', function (): void {
	Assert::exception(fn () => translator(true)->translate('page.count', 3, name: 'x'), InvalidArgumentException::class);
	Assert::exception(fn () => translator(true)->translate('page.greeting', ['name' => 'a'], name: 'x'), InvalidArgumentException::class);

	$logger = new FakeLogger();
	Assert::same('3 stránky', translator(false, $logger)->translate('page.count', 3, name: 'x'));
	Assert::count(1, $logger->records);
	Assert::same(ILogger::WARNING, $logger->records[0][1]);
});


test('a named count selects the plural form', function (): void {
	Assert::same('3 stránky', translator()->translate('page.count', count: 3));
	Assert::same('Jana má 3 stránky', translator()->translate('page.countByName', count: 3, name: 'Jana'));
	Assert::same('Jana má 5 stránek', translator()->translate('page.countByName', ['count' => 5, 'name' => 'Jana']));
	Assert::same('Jana má 1 stránku', translator()->translate('page.countByName', count: 1, name: 'Jana'));
});


foreach (['string' => '3', 'float' => 1.5, 'null' => null] as $type => $value) {
	test("a named count of type $type is rejected on a plural key", function () use ($value): void {
		Assert::exception(fn () => translator(true)->translate('page.count', count: $value), InvalidArgumentException::class);
		Assert::exception(fn () => translator(false)->translate('page.count', count: $value), InvalidArgumentException::class);
	});
}


test('a named count on a scalar key is an ordinary parameter', function (): void {
	$logger = new FakeLogger();
	Assert::same('Ahoj, 4!', translator(true, $logger)->translate('page.greeting', name: 4));
	Assert::exception(fn () => translator(true)->translate('page.saved', count: 3), InvalidArgumentException::class, "%a%unused parameter 'count'%a%");
	Assert::same('Stránka byla uložena.', translator(false, $logger)->translate('page.saved', count: 3));
	Assert::count(1, $logger->records);
});


test('mixing an array and named arguments warns when not strict', function (): void {
	$logger = new FakeLogger();
	Assert::same('Ahoj, a!', translator(false, $logger)->translate('page.greeting', ['name' => 'a'], name: 'x'));
	Assert::count(1, $logger->records);
	Assert::same(ILogger::WARNING, $logger->records[0][1]);
});


test('a non-scalar named argument warns when not strict', function (): void {
	$logger = new FakeLogger();
	Assert::same('Ahoj, %name%!', translator(false, $logger)->translate('page.greeting', name: ['x']));
	Assert::count(1, $logger->records);
	Assert::same(ILogger::WARNING, $logger->records[0][1]);
});


test('a plural key rejects named arguments without a count', function (): void {
	Assert::exception(fn () => translator(true)->translate('page.count', name: 'x'), InvalidArgumentException::class);
});


test('a plural count needs no %count% and an extra argument warns when not strict', function (): void {
	$logger = new FakeLogger();
	Assert::same('5 stránek', translator(false, $logger)->translate('page.count', 5));
	Assert::same([], $logger->records);

	Assert::same('5 stránek', translator(false, $logger)->translate('page.count', 5, 'extra'));
	Assert::count(1, $logger->records);
	Assert::same(ILogger::WARNING, $logger->records[0][1]);
});


$latte = function (?string $key, string $template): string {
	$engine = container()->getByType(LatteFactory::class)->create();
	$engine->setLoader(new StringLoader());
	$engine->addExtension(new TranslatorExtension(translator(true), $key));

	return $engine->renderToString($template);
};
// Compile-time translation wraps the translator's exception in a CompileException.
foreach (['runtime filter' => [null, InvalidArgumentException::class], 'compile-time' => ['cs', CompileException::class]] as $mode => [$key, $failure]) {
	test("Latte passes named arguments to translate(): $mode", function () use ($latte, $key, $failure): void {
		Assert::same('Ahoj, Jana!', $latte($key, "{_page.greeting, name: 'Jana'}"));
		Assert::same('Ahoj, Eva!', $latte($key, "{var \$n = 'Eva'}{_page.greeting, name: \$n}"));
		Assert::same('5 stránek', $latte($key, '{_page.count, count: 5}'));
		Assert::same('Jana má 2 stránky', $latte($key, "{_page.countByName, count: 2, name: 'Jana'}"));
		Assert::exception(fn () => $latte($key, "{_page.saved, foo: 'x'}"), $failure, "%a%unused parameter 'foo'%a%");
	});
}
