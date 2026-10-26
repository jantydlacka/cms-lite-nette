<?php

declare(strict_types=1);

use Nette\Neon\Neon;
use Nette\Utils\FileSystem;
use Nette\Utils\Strings;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';

/**
 * Parses the messages table; every row after the header and separator must be a key/Czech/English row.
 *
 * @return array<string, array{cs: string, en: string}> key => texts
 */
function specMessages(string $markdown): array
{
	$messages = [];
	$row = 0;

	foreach (explode("\n", $markdown) as $number => $line) {
		if (!str_starts_with(ltrim($line), '|')) {
			continue;
		}

		$row++;
		if ($row <= 2) {
			continue;
		}

		$match = Strings::match($line, '~^\|\s*`([^`]+)`\s*\|\s*([^|\s][^|]*?)\s*\|\s*([^|\s][^|]*?)\s*\|$~');
		if ($match === null) {
			throw new UnexpectedValueException('Malformed messages table row on line ' . ($number + 1) . '.');
		}
		if (isset($messages[$match[1]])) {
			throw new UnexpectedValueException("Duplicate key '{$match[1]}' on line " . ($number + 1) . '.');
		}

		$messages[$match[1]] = ['cs' => $match[2], 'en' => $match[3]];
	}

	return $messages;
}


test('spec/messages.md lists exactly the keys and texts of lang/cs.neon and lang/en.neon', function (): void {
	$spec = specMessages(FileSystem::read(__DIR__ . '/../../spec/messages.md'));
	Assert::notSame([], $spec);

	foreach (['cs', 'en'] as $locale) {
		$catalog = Neon::decode(FileSystem::read(__DIR__ . "/../../lang/$locale.neon"));
		assert(is_array($catalog));
		Assert::equal($catalog, array_map(static fn (array $texts): string => $texts[$locale], $spec), $locale);
	}
});


$header = "| Key | Czech | English |\n|-----|-------|---------|\n";

foreach ([
	'key without backticks' => "| a.b | Text | Text |",
	'missing text' => "| `a.b` |",
	'missing English text' => "| `a.b` | Text |",
	'empty text' => "| `a.b` |  | Text |",
	'empty English text' => "| `a.b` | Text |  |",
	'extra column' => "| `a.b` | Text | Text | Text |",
	'missing closing pipe' => "| `a.b` | Text | Text",
	'duplicate key' => "| `a.b` | One | One |\n| `a.b` | Two | Two |",
] as $name => $rows) {
	test("malformed table row is rejected: $name", function () use ($header, $rows): void {
		Assert::exception(fn () => specMessages($header . $rows), UnexpectedValueException::class);
	});
}


test('a well-formed table parses', function () use ($header): void {
	Assert::same(['a.b' => ['cs' => 'Text', 'en' => 'Words']], specMessages("# Title\n\n" . $header . "| `a.b` | Text | Words |\n"));
});
