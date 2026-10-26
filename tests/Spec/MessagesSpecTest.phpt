<?php

declare(strict_types=1);

use Nette\Neon\Neon;
use Nette\Utils\FileSystem;
use Nette\Utils\Strings;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';

/**
 * Parses the messages table; every row after the header and separator must be a key/text row.
 *
 * @return array<string, string> key => text
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

		$match = Strings::match($line, '~^\|\s*`([^`]+)`\s*\|\s*(\S.*?)\s*\|$~');
		if ($match === null) {
			throw new UnexpectedValueException('Malformed messages table row on line ' . ($number + 1) . '.');
		}
		if (isset($messages[$match[1]])) {
			throw new UnexpectedValueException("Duplicate key '{$match[1]}' on line " . ($number + 1) . '.');
		}

		$messages[$match[1]] = $match[2];
	}

	return $messages;
}


test('spec/messages.md lists exactly the keys and texts of lang/cs.neon', function (): void {
	$catalog = Neon::decode(FileSystem::read(__DIR__ . '/../../lang/cs.neon'));
	assert(is_array($catalog));
	$spec = specMessages(FileSystem::read(__DIR__ . '/../../spec/messages.md'));

	Assert::notSame([], $spec);
	Assert::equal($catalog, $spec);
});


$header = "| Key | Czech |\n|-----|-------|\n";

foreach ([
	'key without backticks' => "| a.b | Text |",
	'missing text' => "| `a.b` |",
	'empty text' => "| `a.b` |  |",
	'missing closing pipe' => "| `a.b` | Text",
	'duplicate key' => "| `a.b` | One |\n| `a.b` | Two |",
] as $name => $rows) {
	test("malformed table row is rejected: $name", function () use ($header, $rows): void {
		Assert::exception(fn () => specMessages($header . $rows), UnexpectedValueException::class);
	});
}


test('a well-formed table parses', function () use ($header): void {
	Assert::same(['a.b' => 'Text'], specMessages("# Title\n\n" . $header . "| `a.b` | Text |\n"));
});
