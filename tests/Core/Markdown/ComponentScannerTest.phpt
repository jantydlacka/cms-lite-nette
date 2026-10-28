<?php

declare(strict_types=1);

use App\Core\Markdown\Component\CommonMarkComponentScanner;
use App\Model\Content\ComponentMarker;
use App\Model\Content\ComponentScanner;
use App\Model\Content\ContentBlocks;
use App\Model\Content\ContentContext;
use App\Model\Content\ContentKind;
use App\Model\Content\MarkdownRenderer;
use App\Model\Content\MarkerError;
use Nette\Utils\FileSystem;
use Nette\Utils\Json;
use Tester\Assert;

use function Tests\container;

require __DIR__ . '/../../bootstrap.php';

/** @return list<array{name: string, markdown: string, markers: list<array{line: int, type: string, arguments: list<string>}>, errors: list<array{line: int, kind: string}>, html?: string}> */
function cases(): array
{
	$cases = Json::decode(FileSystem::read(__DIR__ . '/../../../spec/components-cases.json'), forceArrays: true);
	assert(is_array($cases));

	/** @var list<array{name: string, markdown: string, markers: list<array{line: int, type: string, arguments: list<string>}>, errors: list<array{line: int, kind: string}>, html?: string}> */
	return $cases;
}


test('the scanner finds exactly the markers and errors of every specified case', function (): void {
	$scanner = new CommonMarkComponentScanner();
	Assert::true(count(cases()) >= 30, 'the case file looks truncated');

	foreach (cases() as $case) {
		$found = $scanner->scan($case['markdown']);
		$markers = $errors = [];
		foreach ($found as $marker) {
			if ($marker->error === null) {
				$markers[] = ['line' => $marker->line, 'type' => $marker->type, 'arguments' => $marker->arguments];
			} else {
				$errors[] = ['line' => $marker->line, 'kind' => match ($marker->error) {
					MarkerError::Syntax => 'syntax',
					MarkerError::Misplaced => 'misplaced',
				}];
			}
		}

		Assert::same($case['markers'], $markers, $case['name']);
		Assert::same($case['errors'], $errors, $case['name']);
		Assert::same(array_map(static fn (ComponentMarker $m): int => $m->line, $found), array_values(array_unique(array_map(static fn (ComponentMarker $m): int => $m->line, $found))), $case['name'] . ': one marker per line');
	}
});


test('a found marker keeps its physical line as the source, line ending included', function (): void {
	$scanner = new CommonMarkComponentScanner();

	foreach (cases() as $case) {
		$lines = ContentBlocks::lines($case['markdown']);
		foreach ($scanner->scan($case['markdown']) as $marker) {
			Assert::same($lines[$marker->line - 1], $marker->source, $case['name']);
		}
	}
});


test('splitting by the found markers and joining again gives the original text', function (): void {
	$scanner = new CommonMarkComponentScanner();

	foreach (cases() as $case) {
		$blocks = ContentBlocks::split($case['markdown'], $scanner->scan($case['markdown']));

		Assert::same($case['markdown'], ContentBlocks::join($blocks), $case['name']);
	}
});


test('cases with html render to exactly that html', function (): void {
	$renderer = container()->getByType(MarkdownRenderer::class);

	foreach (cases() as $case) {
		if (isset($case['html'])) {
			Assert::same($case['html'], $renderer->render($case['markdown'], new ContentContext(ContentKind::Page, 1, 'cs', false)), $case['name']);
		}
	}
});


test('container binds the scanner port to the commonmark scanner', function (): void {
	Assert::type(CommonMarkComponentScanner::class, container()->getByType(ComponentScanner::class));
});
