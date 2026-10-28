<?php

declare(strict_types=1);

use App\Model\Content\ComponentRegistry;
use App\Model\Content\ContentKind;
use App\Model\Content\InvalidComponentArguments;
use App\Model\News\News;
use App\Model\News\NewsListComponent;
use App\Model\News\NewsListItem;
use App\Model\News\NewsTranslation;
use App\Model\PublicationStatus;
use Tester\Assert;
use Tests\Support\FakeNewsRepository;

require __DIR__ . '/../../bootstrap.php';

function item(int $id): NewsListItem
{
	$now = new DateTimeImmutable('2026-01-01 00:00:00');

	return new NewsListItem(
		new News($id, $now, null, 1, $now),
		new NewsTranslation($id, 'cs', "N$id", "n$id", 'Perex', '', '', PublicationStatus::Published, $now, 1),
	);
}


test('the component is called novinky and is allowed in pages only', function (): void {
	$component = new NewsListComponent(new FakeNewsRepository());
	Assert::same('novinky', $component->name());
	Assert::true($component->allowedIn(ContentKind::Page));
	Assert::false($component->allowedIn(ContentKind::News));
});


test('arguments give the count, three by default', function (): void {
	$component = new NewsListComponent(new FakeNewsRepository());
	Assert::same(['count' => 3], $component->arguments([]));
	Assert::same(['count' => 5], $component->arguments(['5']));
	Assert::same(['count' => 1], $component->arguments(['1']));
	Assert::same(['count' => 20], $component->arguments(['20']));
	Assert::same(['count' => 12], $component->arguments(['12']));
});


test('anything but a whole number from 1 to 20 is refused', function (): void {
	$component = new NewsListComponent(new FakeNewsRepository());
	foreach ([['0'], ['21'], ['pet'], ['3', '4'], ['-1'], ['03'], ['1.5'], ['100'], ['']] as $arguments) {
		$e = Assert::exception(
			static fn () => $component->arguments($arguments),
			InvalidComponentArguments::class,
		);
		assert($e instanceof InvalidComponentArguments);
		Assert::same('news.component.arguments', $e->messageKey);
	}
});


test('items are the visible news of the locale, as many as the count', function (): void {
	$repository = new FakeNewsRepository([item(3), item(2), item(1)]);
	$component = new NewsListComponent($repository);

	Assert::same([3, 2], array_map(static fn (NewsListItem $i): int => $i->news->id, $component->items(['count' => 2], 'en')));
	Assert::same([['en', 2, 0]], $repository->calls);
});


test('the registry finds components by name and lists the names', function (): void {
	$component = new NewsListComponent(new FakeNewsRepository());
	$registry = new ComponentRegistry(['novinky' => $component]);
	Assert::same($component, $registry->get('novinky'));
	Assert::null($registry->get('neznamy'));
	Assert::same(['novinky'], $registry->names());
	Assert::exception(
		static fn () => new ComponentRegistry(['jine' => $component]),
		InvalidArgumentException::class,
	);
});
