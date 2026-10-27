<?php

declare(strict_types=1);

use App\Core\Latte\CmsLatteExtension;
use App\Core\Localization\CurrentLocale;
use App\Core\Localization\NeonTranslator;
use App\Core\Localization\SiteTime;
use App\Model\CmsConfig;
use App\Model\Content\MarkdownRenderer;
use Tester\Assert;
use Tests\FakeLogger;
use function Tests\container;

require __DIR__ . '/../../bootstrap.php';

$config = new CmsConfig('Demo', 'cs', ['cs', 'en'], 10);
$locale = new CurrentLocale($config);
$extension = new CmsLatteExtension(
	container()->getByType(MarkdownRenderer::class),
	new NeonTranslator(__DIR__ . '/../../../lang', $locale, true, new FakeLogger()),
	new SiteTime($config),
);
$filters = $extension->getFilters();
$moment = new DateTimeImmutable('2025-12-31 23:30:00', new DateTimeZone('UTC'));


test('siteDate formats the site-zone day in the current language', function () use ($filters, $locale, $moment): void {
	$locale->set('cs');
	Assert::same('1. 1. 2026', $filters['siteDate']($moment));
	$locale->set('en');
	Assert::same('1 Jan 2026', $filters['siteDate']($moment));
});


test('siteDateTime adds the site-zone time', function () use ($filters, $locale, $moment): void {
	$locale->set('cs');
	Assert::same('1. 1. 2026 00:30', $filters['siteDateTime']($moment));
	$locale->set('en');
	Assert::same('1 Jan 2026 00:30', $filters['siteDateTime']($moment));
});
