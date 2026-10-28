<?php

declare(strict_types=1);

use App\Core\Markdown\Component\CommonMarkComponentScanner;
use App\Model\Content\ComponentRegistry;
use App\Model\Content\ContentChecker;
use App\Model\Content\ContentKind;
use App\Model\Content\ContentProblem;
use App\Model\News\NewsListComponent;
use Nette\Utils\FileSystem;
use Nette\Utils\Json;
use Tester\Assert;
use Tests\Support\FakeNewsRepository;

require __DIR__ . '/../../bootstrap.php';

function checker(): ContentChecker
{
	return new ContentChecker(
		new CommonMarkComponentScanner(),
		new ComponentRegistry(['novinky' => new NewsListComponent(new FakeNewsRepository())]),
	);
}


/** @return list<array{int, string, array<string, mixed>}> */
function summary(string $markdown, ContentKind $kind): array
{
	return array_map(
		static fn (ContentProblem $p): array => [$p->line, $p->messageKey, $p->parameters],
		checker()->check($markdown, $kind),
	);
}


test('every error case of the specification is reported on its line with the matching message', function (): void {
	/** @var list<array{name: string, markdown: string, errors: list<array{line: int, kind: string}>}> $cases */
	$cases = Json::decode(FileSystem::read(__DIR__ . '/../../../spec/components-cases.json'), forceArrays: true);
	$checked = 0;
	foreach ($cases as $case) {
		$expected = array_map(static fn (array $e): array => [
			$e['line'],
			'content.component.' . $e['kind'],
			['line' => $e['line']],
		], $case['errors']);
		foreach ([ContentKind::Page, ContentKind::News] as $kind) {
			// A case may also hold valid markers; only the specified error lines are compared.
			$errors = array_values(array_filter(
				summary($case['markdown'], $kind),
				static fn (array $p): bool => in_array($p[1], ['content.component.syntax', 'content.component.misplaced'], true),
			));
			Assert::same($expected, $errors, $case['name']);
			$checked += count($expected);
		}
	}

	Assert::true($checked >= 30, 'the error cases look truncated');
});


test('valid markers pass in a page and the news component is refused in a news item', function (): void {
	Assert::same([], summary("Text\n\n::: novinky\n::: novinky 20\n", ContentKind::Page));
	Assert::same(
		[
			[3, 'content.component.notAllowedInNews', ['line' => 3, 'type' => 'novinky']],
			[4, 'content.component.notAllowedInNews', ['line' => 4, 'type' => 'novinky']],
		],
		summary("Text\n\n::: novinky\n::: novinky 20\n", ContentKind::News),
	);
	Assert::same([], summary("Plain text, `::: novinky`, \\::: novinky\n\n```\n::: novinky 0\n```\n", ContentKind::News));
});


test('an unknown type lists the available components', function (): void {
	Assert::same(
		[[2, 'content.component.unknown', ['line' => 2, 'type' => 'neznamy', 'available' => 'novinky']]],
		summary("a\n::: neznamy 3\n", ContentKind::Page),
	);
	Assert::same(
		[[1, 'content.component.unknown', ['line' => 1, 'type' => 'novinkyy', 'available' => 'novinky']]],
		summary("::: novinkyy\n", ContentKind::News),
	);
});


test('invalid arguments report the key of the component', function (): void {
	foreach (['0', '21', 'pet', '3 4', '03', '100'] as $arguments) {
		Assert::same(
			[[1, 'news.component.arguments', ['line' => 1]]],
			summary("::: novinky $arguments\n", ContentKind::Page),
			$arguments,
		);
	}
});


test('problems come in line order across all sources', function (): void {
	$markdown = "::: neznamy\n- ::: novinky\n::: novinky 0\n:::x\n";
	Assert::same(
		[1, 2, 3, 4],
		array_map(static fn (ContentProblem $p): int => $p->line, checker()->check($markdown, ContentKind::Page)),
	);
	Assert::same(
		['content.component.unknown', 'content.component.misplaced', 'news.component.arguments', 'content.component.syntax'],
		array_map(static fn (ContentProblem $p): string => $p->messageKey, checker()->check($markdown, ContentKind::Page)),
	);
});
