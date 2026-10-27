<?php

declare(strict_types=1);

use App\Model\CmsConfig;
use App\Model\Page\ReservedSegments;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';

$reserved = new ReservedSegments(new CmsConfig('Site', 'cs', ['cs', 'en'], 10));

test('all lists the fixed segments and the locale codes once', function () use ($reserved): void {
	Assert::same(['admin', 'sitemap.xml', 'novinky', 'news', 'cs', 'en'], $reserved->all());
});

test('locale codes that repeat a fixed segment are not duplicated', function (): void {
	$reserved = new ReservedSegments(new CmsConfig('Site', 'admin', ['admin', 'cs'], 10));
	Assert::same(['admin', 'sitemap.xml', 'novinky', 'news', 'cs'], $reserved->all());
});

test('only the first segment is compared, case-insensitively', function () use ($reserved): void {
	foreach (['admin', 'Novinky', 'EN', 'cs/stranka', 'en/x/y', 'sitemap.xml', 'News/2026'] as $path) {
		Assert::true($reserved->isReserved($path), $path);
	}
	foreach (['o-nas', 'o-nas/admin', 'administrace', 'de', 'sitemap', ''] as $path) {
		Assert::false($reserved->isReserved($path), $path);
	}
});

test('both news segments are reserved even when only cs is configured', function (): void {
	$reserved = new ReservedSegments(new CmsConfig('Site', 'cs', ['cs'], 10));
	Assert::true($reserved->isReserved('novinky'));
	Assert::true($reserved->isReserved('news/x'));
});
