<?php

declare(strict_types=1);

use Nette\Neon\Neon;
use Nette\Utils\FileSystem;
use Nette\Utils\Strings;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';

/** @return array<string, string> key => text from the table in spec/messages.md */
function specMessages(): array
{
	$messages = [];

	foreach (explode("\n", FileSystem::read(__DIR__ . '/../../spec/messages.md')) as $line) {
		$match = Strings::match($line, '~^\|\s*`([^`]+)`\s*\|\s*(.+?)\s*\|$~');

		if ($match !== null) {
			$messages[$match[1]] = $match[2];
		}
	}

	return $messages;
}


test('spec/messages.md lists exactly the keys and texts of lang/cs.neon', function (): void {
	$catalog = Neon::decode(FileSystem::read(__DIR__ . '/../../lang/cs.neon'));
	assert(is_array($catalog));
	$spec = specMessages();

	Assert::notSame([], $spec);
	Assert::equal($catalog, $spec);
});
