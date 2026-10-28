<?php

declare(strict_types=1);

use App\Core\Markdown\CommonMarkEnvironment;
use App\Core\Markdown\CommonMarkRenderer;
use App\Core\Markdown\Component\CommonMarkComponentScanner;
use App\Core\Markdown\Component\ComponentStartParser;
use App\Core\Markdown\ComponentWarningGate;
use App\Model\Content\ComponentRegistry;
use App\Model\Content\ContentChecker;
use App\Model\Content\ContentContext;
use App\Model\Content\ContentKind;
use App\Model\Content\ContentProblem;
use App\Model\Content\MarkdownRenderer;
use App\Model\News\NewsListComponent;
use Latte\Loaders\StringLoader;
use Latte\Runtime\Html;
use Nette\Bridges\ApplicationLatte\LatteFactory;
use Nette\Caching\Storages\MemoryStorage;
use Nette\Utils\FileSystem;
use Nette\Utils\Json;
use Tester\Assert;
use Tests\FakeLogger;
use Tests\Support\FakeComponentHtmlRenderer;
use Tests\Support\FakeNewsRepository;
use Tests\Support\IntroComponent;
use Tracy\ILogger;

use function Tests\container;

require __DIR__ . '/../../bootstrap.php';


// A shared $gate stands for the persistent cache that separate requests share.
function renderer(?FakeComponentHtmlRenderer $components = null, ?FakeLogger $logger = null, ?ComponentWarningGate $gate = null): CommonMarkRenderer
{
	$registry = new ComponentRegistry(['novinky' => new NewsListComponent(new FakeNewsRepository()), 'uvod' => new IntroComponent()]);

	return new CommonMarkRenderer(
		new ContentChecker(new CommonMarkComponentScanner(), $registry),
		$registry,
		$components ?? new FakeComponentHtmlRenderer(),
		$logger ?? new FakeLogger(),
		$gate ?? new ComponentWarningGate(new MemoryStorage()),
	);
}


function website(ContentKind $kind = ContentKind::Page, string $locale = 'cs', int $id = 7): ContentContext
{
	return new ContentContext($kind, $id, $locale, false);
}


function preview(ContentKind $kind = ContentKind::Page, string $locale = 'cs'): ContentContext
{
	return new ContentContext($kind, 7, $locale, true);
}


function renderWeb(string $markdown): string
{
	return renderer()->render($markdown, website());
}


/** @return list<string> */
function loggedWarnings(FakeLogger $logger): array
{
	return array_map(static function (array $record): string {
		Assert::same(ILogger::WARNING, $record[1]);
		assert(is_string($record[0]));

		return $record[0];
	}, $logger->records);
}

test('headings are not shifted and normal links are kept', function (): void {
	$renderer = renderer();
	$renderer->render('', website());

	Assert::same("<h1>Nadpis</h1>\n", $renderer->render('# Nadpis', website()));
	Assert::same("<h2>Nadpis</h2>\n", $renderer->render('## Nadpis', website()));
	Assert::contains('<a href="https://example.com/a?b=1">x</a>', $renderer->render('[x](https://example.com/a?b=1)', website()));
});


test('raw html is escaped, never passed through', function (): void {
	foreach (['<script>alert(1)</script>', '<img src=x onerror=alert(1)>', 'text <img src=x onerror=alert(1)> text'] as $markdown) {
		$html = renderWeb($markdown);

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
		$html = renderWeb($markdown);

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
		$html = renderWeb($markdown);

		Assert::notContains('<script', $html, $markdown);
		Assert::same(0, preg_match('~<[^>]+\b(?:href|src)\s*=\s*["\']?\s*javascript~i', $html), $markdown . ' => ' . $html);
	}

	Assert::contains('&lt;a href=', renderWeb('<a href="javascript:x">y</a>'));
	Assert::false(stripos(renderWeb('![x](javascript:alert(1))'), 'javascript'));
});


test('latte filter markdown outputs html through the container wiring', function (): void {
	$latte = container()->getByType(LatteFactory::class)->create();
	$latte->setLoader(new StringLoader(['t' => '{$md|markdown: $context}|{$md}']));
	$out = $latte->renderToString('t', ['md' => "## T\n\n<script>x</script>", 'context' => website()]);
	[$rendered, $escaped] = explode('|', $out, 2);

	Assert::same("<h2>T</h2>\n&lt;script&gt;x&lt;/script&gt;\n", $rendered);
	Assert::false(str_contains($escaped, '<h2>'));
});


test('container binds the interface to the commonmark renderer', function (): void {
	Assert::type(CommonMarkRenderer::class, container()->getByType(MarkdownRenderer::class));
	Assert::type(Html::class, container()->getByType(LatteFactory::class)->create()->invokeFilter('markdown', ['**a**', website()]));
});


test('content without component markers renders byte for byte as before markers existed', function (): void {
	// The fixture holds the demo pages and news plus a few edge cases, rendered before component markers were parsed.
	$cases = Json::decode(FileSystem::read(__DIR__ . '/fixtures/main-rendered-content.json'), forceArrays: true);
	assert(is_array($cases));
	Assert::true(count($cases) > 20, 'the fixture looks truncated');
	$renderer = renderer();

	foreach ($cases as $case) {
		assert(is_array($case) && is_string($case['name']) && is_string($case['markdown']) && is_string($case['html']));
		Assert::same($case['html'], $renderer->render($case['markdown'], website()), $case['name']);
		Assert::same($case['html'], $renderer->render($case['markdown'], preview()), $case['name']);
	}
});


test('a valid marker renders its component in its place, with the arguments and language of the context', function (): void {
	$components = new FakeComponentHtmlRenderer();
	$renderer = renderer($components);

	Assert::same(
		"<p>Úvod</p>\n<div class=\"fake\">novinky {&quot;count&quot;:2} en</div>\n<p>Konec</p>\n",
		$renderer->render("Úvod\n\n::: novinky 2\n\nKonec\n", website(locale: 'en')),
	);
	// Like an ATX heading, the marker ends the paragraph above it.
	Assert::same("<p>a</p>\n<div class=\"fake\">uvod [] cs</div>\n<p>b</p>\n", $renderer->render("a\n::: uvod\nb\n", website()));
	Assert::same("<div class=\"fake\">novinky {&quot;count&quot;:3} cs preview</div>\n", $renderer->render('::: novinky', preview()));
	Assert::same(['novinky {"count":2} en', 'uvod [] cs', 'novinky {"count":3} cs preview'], $components->rendered);
});


test('a component that renders nothing leaves no trace in the html', function (): void {
	Assert::same("<p>a</p>\n<p>b</p>\n", renderer(new FakeComponentHtmlRenderer(empty: true))->render("a\n\n::: novinky\n\nb\n", website()));
});


test('code and the escaped marker stay text and render no component', function (): void {
	$components = new FakeComponentHtmlRenderer();
	$logger = new FakeLogger();
	$renderer = renderer($components, $logger);

	Assert::same("<pre><code>::: novinky 3\n</code></pre>\n", $renderer->render("```\n::: novinky 3\n```\n", website()));
	Assert::same("<p><code>::: novinky</code></p>\n", $renderer->render('`::: novinky`', website()));
	Assert::same("<p>::: novinky</p>\n", $renderer->render('\::: novinky', website()));
	Assert::same([], $components->rendered);
	Assert::same([], $logger->records);
});


test('on the website a broken stored marker is left out and logged as a warning', function (): void {
	$components = new FakeComponentHtmlRenderer();
	$logger = new FakeLogger();
	$markdown = "::: neznamy\n- ::: novinky\n\n::: novinky 0\n:::x\n\n<div>\n::: uvod\n</div>\n\nKonec\n";

	$html = renderer($components, $logger)->render($markdown, website(id: 12));

	Assert::same("<ul>\n<li></li>\n</ul>\n&lt;div&gt;\n&lt;/div&gt;\n<p>Konec</p>\n", $html);
	Assert::same([], $components->rendered);
	Assert::same([], $components->problems);
	Assert::same([
		'Component marker left out: "neznamy" on line 1 of page 12 (cs), content.component.unknown.',
		'Component marker left out: "novinky" on line 2 of page 12 (cs), content.component.misplaced.',
		'Component marker left out: "novinky" on line 4 of page 12 (cs), news.component.arguments.',
		'Component marker left out: malformed ":::x" on line 5 of page 12 (cs), content.component.syntax.',
		'Component marker left out: "uvod" on line 8 of page 12 (cs), content.component.misplaced.',
	], loggedWarnings($logger));
});


test('a news item leaves out the news component on the website and reports it in the preview', function (): void {
	$logger = new FakeLogger();
	$renderer = renderer(logger: $logger);

	Assert::same("<p>Text</p>\n", $renderer->render("Text\n\n::: novinky\n", website(ContentKind::News, 'en', 5)));
	Assert::same(['Component marker left out: "novinky" on line 3 of news 5 (en), content.component.notAllowedInNews.'], loggedWarnings($logger));
	Assert::same(
		"<p>Text</p>\n<p class=\"problem\">content.component.notAllowedInNews 3</p>\n",
		$renderer->render("Text\n\n::: novinky\n", preview(ContentKind::News, 'en')),
	);
	Assert::count(1, $logger->records, 'the preview logs nothing');
});


test('a broken marker is logged at most once per content, language and line', function (): void {
	$logger = new FakeLogger();
	$renderer = renderer(logger: $logger);

	$renderer->render("::: neznamy\n", website(id: 1));
	$renderer->render("::: neznamy\n", website(id: 1));
	$renderer->render("Intro\n\n::: neznamy\n", website(id: 1));
	$renderer->render("::: neznamy\n", website(id: 1, locale: 'en'));
	$renderer->render("::: neznamy\n", website(id: 2));
	$renderer->render("::: neznamy\n", website(ContentKind::News, id: 1));

	Assert::same([
		'Component marker left out: "neznamy" on line 1 of page 1 (cs), content.component.unknown.',
		'Component marker left out: "neznamy" on line 3 of page 1 (cs), content.component.unknown.',
		'Component marker left out: "neznamy" on line 1 of page 1 (en), content.component.unknown.',
		'Component marker left out: "neznamy" on line 1 of page 2 (cs), content.component.unknown.',
		'Component marker left out: "neznamy" on line 1 of news 1 (cs), content.component.unknown.',
	], loggedWarnings($logger));
});


test('separate requests log a broken marker once until the content changes', function (): void {
	$logger = new FakeLogger();
	$gate = new ComponentWarningGate(new MemoryStorage());

	renderer(logger: $logger, gate: $gate)->render("::: neznamy\n", website(id: 1));
	renderer(logger: $logger, gate: $gate)->render("::: neznamy\n", website(id: 1));
	renderer(logger: $logger, gate: $gate)->render("::: neznamy\n\nText\n", website(id: 1));

	Assert::same([
		'Component marker left out: "neznamy" on line 1 of page 1 (cs), content.component.unknown.',
		'Component marker left out: "neznamy" on line 1 of page 1 (cs), content.component.unknown.',
	], loggedWarnings($logger));
});


test('a long malformed marker is logged shortened', function (): void {
	$logger = new FakeLogger();

	renderer(logger: $logger)->render(':::' . str_repeat('x', 200) . "\n", website(id: 1));

	$warnings = loggedWarnings($logger);
	Assert::count(1, $warnings);
	Assert::match('Component marker left out: malformed ":::xxx%a%…" on line 1 of page 1 (cs), content.component.syntax.', $warnings[0]);
	Assert::same(1, preg_match('~malformed "([^"]*)"~u', $warnings[0], $m));
	Assert::same(80, mb_strlen($m[1] ?? ''));
});


test('the preview shows the problem of every broken marker in its place', function (): void {
	$components = new FakeComponentHtmlRenderer();
	$logger = new FakeLogger();
	$markdown = "::: neznamy\n- ::: novinky\n\n::: novinky 0\n:::x\n\n<div>\n::: uvod\n</div>\n\nKonec\n";

	$html = renderer($components, $logger)->render($markdown, preview());

	Assert::same(
		"<p class=\"problem\">content.component.unknown 1</p>\n"
		. "<ul>\n<li>\n<p class=\"problem\">content.component.misplaced 2</p>\n</li>\n</ul>\n"
		. "<p class=\"problem\">news.component.arguments 4</p>\n"
		. "<p class=\"problem\">content.component.syntax 5</p>\n"
		. "&lt;div&gt;\n&lt;/div&gt;\n"
		. "<p class=\"problem\">content.component.misplaced 8</p>\n"
		. "<p>Konec</p>\n",
		$html,
	);
	Assert::same(['line' => 1, 'type' => 'neznamy', 'available' => 'novinky, uvod'], $components->problems[0]->parameters);
	Assert::same([], $components->rendered);
	Assert::same([], $logger->records);
});


test('the renderer treats exactly the lines the scanner reports, for every specified case', function (): void {
	$cases = Json::decode(FileSystem::read(__DIR__ . '/../../../spec/components-cases.json'), forceArrays: true);
	assert(is_array($cases));

	foreach ($cases as $case) {
		assert(is_array($case) && is_string($case['name']) && is_string($case['markdown']) && is_array($case['markers']) && is_array($case['errors']));
		$components = new FakeComponentHtmlRenderer();
		renderer($components)->render($case['markdown'], preview());

		$problemLines = array_map(static fn (ContentProblem $p): int => $p->line, $components->problems);
		Assert::same(count($case['markers']) + count($case['errors']), count($components->rendered) + count($problemLines), $case['name']);
		foreach ($case['errors'] as $error) {
			assert(is_array($error));
			Assert::contains($error['line'], $problemLines, $case['name']);
		}
	}
});


test('checking and rendering parse with the same environment', function (): void {
	$environment = CommonMarkEnvironment::create();
	$parsers = array_map(get_class(...), iterator_to_array($environment->getBlockStartParsers(), false));

	Assert::contains(ComponentStartParser::class, $parsers);
	Assert::same('escape', $environment->getConfiguration()->get('html_input'));
	Assert::false($environment->getConfiguration()->get('allow_unsafe_links'));
});
