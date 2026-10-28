<?php

declare(strict_types=1);

use App\Model\Content\ComponentMarker;
use App\Model\Content\ContentBlocks;
use App\Model\Content\MarkerError;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';

test('lines keep their own line endings', function (): void {
	Assert::same([], ContentBlocks::lines(''));
	Assert::same(['a'], ContentBlocks::lines('a'));
	Assert::same(["a\n"], ContentBlocks::lines("a\n"));
	Assert::same(["a\r\n", "\r", "b\n", 'c'], ContentBlocks::lines("a\r\n\rb\nc"));
	Assert::same(["\n", "\n"], ContentBlocks::lines("\n\n"));
});


test('split cuts the text at marker lines and join puts it back', function (): void {
	$markdown = "Intro\n\n::: novinky 3\nOutro\n::: novinky\n";
	$first = new ComponentMarker(3, 'novinky', ['3'], null, "::: novinky 3\n");
	$second = new ComponentMarker(5, 'novinky', [], null, "::: novinky\n");

	$blocks = ContentBlocks::split($markdown, [$first, $second]);

	Assert::same(["Intro\n\n", $first, "Outro\n", $second], $blocks);
	Assert::same($markdown, ContentBlocks::join($blocks));
});


test('a malformed top-level marker is cut out, a misplaced one stays in the text', function (): void {
	$markdown = "- ::: novinky\n:::novinky\r\nEnd";
	$misplaced = new ComponentMarker(1, 'novinky', [], MarkerError::Misplaced, "- ::: novinky\n");
	$malformed = new ComponentMarker(2, null, [], MarkerError::Syntax, ":::novinky\r\n");

	$blocks = ContentBlocks::split($markdown, [$misplaced, $malformed]);

	Assert::same(["- ::: novinky\n", $malformed, 'End'], $blocks);
	Assert::same($markdown, ContentBlocks::join($blocks));
});


test('text without markers stays one block, empty text no block', function (): void {
	Assert::same(["a\nb"], ContentBlocks::split("a\nb", []));
	Assert::same([], ContentBlocks::split('', []));
	Assert::same('', ContentBlocks::join([]));
});


test('a marker that does not match its line is refused', function (): void {
	Assert::exception(
		static fn () => ContentBlocks::split("a\n::: novinky\n", [new ComponentMarker(1, 'novinky', [], null, "::: novinky\n")]),
		InvalidArgumentException::class,
	);
	Assert::exception(
		static fn () => ContentBlocks::split("a\n", [new ComponentMarker(3, 'novinky', [], null, "::: novinky\n")]),
		InvalidArgumentException::class,
	);
});
