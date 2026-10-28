<?php

declare(strict_types=1);

use App\Core\Markdown\CommonMarkRenderer;
use App\Model\Content\MarkdownRenderer;
use Latte\Loaders\StringLoader;
use Latte\Runtime\Html;
use Nette\Bridges\ApplicationLatte\LatteFactory;
use Nette\Utils\FileSystem;
use Nette\Utils\Json;
use Tester\Assert;

use function Tests\container;

require __DIR__ . '/../../bootstrap.php';

test('headings are not shifted and normal links are kept', function (): void {
	$renderer = new CommonMarkRenderer();

	Assert::same("<h1>Nadpis</h1>\n", $renderer->render('# Nadpis'));
	Assert::same("<h2>Nadpis</h2>\n", $renderer->render('## Nadpis'));
	Assert::contains('<a href="https://example.com/a?b=1">x</a>', $renderer->render('[x](https://example.com/a?b=1)'));
});


test('raw html is escaped, never passed through', function (): void {
	foreach (['<script>alert(1)</script>', '<img src=x onerror=alert(1)>', 'text <img src=x onerror=alert(1)> text'] as $markdown) {
		$html = (new CommonMarkRenderer())->render($markdown);

		Assert::false(stripos($html, '<script'), $html);
		Assert::false(stripos($html, '<img'), $html);
		Assert::contains('&lt;', $html);
	}
});


test('unsafe link and image targets never reach the output', function (): void {
	$inputs = [
		'[x](javascript:alert(1))',
		'[x](JaVaScRiPt:alert(1))',
		'[x](java&#x09;script:alert(1))',
		'<javascript:alert(1)>',
		'[x](vbscript:x)',
		'![x](data:text/html,<b>)',
	];

	foreach ($inputs as $markdown) {
		$html = (new CommonMarkRenderer())->render($markdown);

		// An unsafe autolink keeps its text inside a href-less <a>, so only that case may still print the scheme.
		$needles = $markdown === '<javascript:alert(1)>'
			? ['href', 'src', '<script']
			: ['javascript:', 'vbscript:', 'data:text/html', '<script'];

		foreach ($needles as $needle) {
			Assert::false(stripos($html, $needle), "$needle in output of $markdown: $html");
		}
	}
});


test('reviewed XSS probes leave no executable href or src', function (): void {
	$probes = [
		'[x](  javascript:alert(1))',
		'[x](javascript&colon;alert(1))',
		'[x](&#106;avascript:alert(1))',
		'<a href="javascript:x">y</a>',
		'![x](javascript:alert(1))',
		"[x][1]\n\n[1]: javascript:alert(1)",
		'[x](<javascript:alert(1)>)',
	];
	foreach ($probes as $markdown) {
		$html = (new CommonMarkRenderer())->render($markdown);

		Assert::notContains('<script', $html, $markdown);
		Assert::same(0, preg_match('~<[^>]+\b(?:href|src)\s*=\s*["\']?\s*javascript~i', $html), $markdown . ' => ' . $html);
	}

	Assert::contains('&lt;a href=', (new CommonMarkRenderer())->render('<a href="javascript:x">y</a>'));
	Assert::false(stripos((new CommonMarkRenderer())->render('![x](javascript:alert(1))'), 'javascript'));
});


test('latte filter markdown outputs html through the container wiring', function (): void {
	$latte = container()->getByType(LatteFactory::class)->create();
	$latte->setLoader(new StringLoader(['t' => '{$md|markdown}|{$md}']));
	$out = $latte->renderToString('t', ['md' => "## T\n\n<script>x</script>"]);
	[$rendered, $escaped] = explode('|', $out, 2);

	Assert::same("<h2>T</h2>\n&lt;script&gt;x&lt;/script&gt;\n", $rendered);
	Assert::false(str_contains($escaped, '<h2>'));
});


test('container binds the interface to the commonmark renderer', function (): void {
	Assert::type(CommonMarkRenderer::class, container()->getByType(MarkdownRenderer::class));
	Assert::type(Html::class, container()->getByType(LatteFactory::class)->create()->invokeFilter('markdown', ['**a**']));
});


test('content without component markers renders byte for byte as before markers existed', function (): void {
	// The fixture holds the demo pages and news plus a few edge cases, rendered before component markers were parsed.
	$cases = Json::decode(FileSystem::read(__DIR__ . '/fixtures/main-rendered-content.json'), forceArrays: true);
	assert(is_array($cases));
	Assert::true(count($cases) > 20, 'the fixture looks truncated');
	$renderer = new CommonMarkRenderer();

	foreach ($cases as $case) {
		assert(is_array($case) && is_string($case['name']) && is_string($case['markdown']) && is_string($case['html']));
		Assert::same($case['html'], $renderer->render($case['markdown']), $case['name']);
	}
});
