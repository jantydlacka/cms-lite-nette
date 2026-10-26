<?php

declare(strict_types=1);

use App\Core\Localization\PluralRules;
use Nette\Neon\Neon;
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';

const LANG_DIR = __DIR__ . '/../../lang';


/** @return array<mixed> */
function catalog(string $locale): array
{
	$data = Neon::decode(FileSystem::read(LANG_DIR . "/$locale.neon"));
	assert(is_array($data));

	return $data;
}


/**
 * 'string' or the sorted list of plural forms.
 *
 * @param array<mixed> $catalog
 * @return array<string, string|list<string>>
 */
function shape(array $catalog): array
{
	$shape = [];
	foreach ($catalog as $key => $value) {
		$shape[(string) $key] = is_array($value) ? array_map(strval(...), array_keys($value)) : get_debug_type($value);
	}
	ksort($shape);

	return $shape;
}


$files = glob(LANG_DIR . '/*.neon');
$locales = array_map(static fn (string $file): string => basename($file, '.neon'), $files === false ? [] : $files);
sort($locales);


test('the public UI has a Czech and an English catalog', function () use ($locales): void {
	Assert::same(['cs', 'en'], $locales);
});


foreach ($locales as $locale) {
	test("lang/$locale.neon has the same keys and shapes as lang/cs.neon", function () use ($locale): void {
		$expected = array_map(
			static fn (string|array $shape): string|array => is_array($shape) ? PluralRules::for($locale) : $shape,
			shape(catalog('cs')),
		);
		Assert::same($expected, shape(catalog($locale)));
	});

	test("plural maps in lang/$locale.neon have exactly the forms of the language", function () use ($locale): void {
		foreach (catalog($locale) as $key => $value) {
			Assert::true(is_string($value) || is_array($value), "'$key' must be a string or a plural map");
			if (is_array($value)) {
				Assert::same(PluralRules::for($locale), array_keys($value), "'$key'");
			}
		}
	});
}
