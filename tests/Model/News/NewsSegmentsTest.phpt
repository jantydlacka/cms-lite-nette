<?php

declare(strict_types=1);

use App\Model\News\NewsSegments;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';

test('cs has its own segment and page parameter', function (): void {
	Assert::same(['segment' => 'novinky', 'pageParameter' => 'strana'], NewsSegments::forLocale('cs'));
});

test('other locales get the English ones', function (): void {
	foreach (['en', 'de'] as $locale) {
		Assert::same(['segment' => 'news', 'pageParameter' => 'page'], NewsSegments::forLocale($locale), $locale);
	}
});

test('allSegments lists every segment', function (): void {
	Assert::same(['novinky', 'news'], NewsSegments::allSegments());
});

test('allPageParameters lists every page parameter', function (): void {
	Assert::same(['strana', 'page'], NewsSegments::allPageParameters());
});
