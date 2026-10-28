<?php

declare(strict_types=1);

use Tester\Assert;
use Tester\DomQuery;
use Tests\Support\HttpResult;

require __DIR__ . '/../bootstrap.php';

function textOf(string $html): string
{
	$found = DomQuery::fromHtml('<html><body>' . $html . '</body></html>')->find('#t');
	return HttpResult::textContent($found[0]);
}


test('named, numeric and non-breaking entities are decoded', function (): void {
	Assert::same('A & B "q" \'s\' é', textOf('<p id="t">A &amp; B &quot;q&quot; &#39;s&#39; &#233;</p>'));
	Assert::same("a\u{A0}b", textOf('<p id="t">a&nbsp;b</p>'));
});


test('an attribute containing > does not leak into the text', function (): void {
	Assert::same('text', textOf('<p id="t" title="a > b">text</p>'));
});


test('nested elements are flattened and comments dropped', function (): void {
	Assert::same('one two three', textOf('<div id="t"><b>one</b> <i>two <!-- hidden --></i>three</div>'));
});


test('non-ASCII text is kept', function (): void {
	Assert::same('Příliš žluťoučký kůň ✓', textOf('<p id="t">Příliš žluťoučký kůň ✓</p>'));
});
