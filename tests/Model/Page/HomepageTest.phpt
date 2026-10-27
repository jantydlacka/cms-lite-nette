<?php

declare(strict_types=1);

use App\Model\Page\Homepage;
use App\Model\Page\PageRepository;
use App\Model\Page\PageTranslation;
use App\Model\PublicationStatus;
use App\Model\Setting\SettingRepository;
use Tester\Assert;
use Tests\Support\TestDatabase;
use function Tests\container;

require __DIR__ . '/../../bootstrap.php';

$container = container();
$settings = $container->getByType(SettingRepository::class);
$pages = $container->getByType(PageRepository::class);
$homepage = new Homepage($settings, $pages);
$reset = static fn () => TestDatabase::prepare($container);

$translate = static function (int $id, string $locale, PublicationStatus $status) use ($pages): void {
	$pages->saveTranslation(new PageTranslation(
		$id,
		$locale,
		'Home',
		'home',
		'home',
		'',
		'',
		$status,
		new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC')),
		1,
	));
};

test('no setting means no homepage', function () use ($homepage, $reset): void {
	$reset();
	Assert::null($homepage->pageId());
	Assert::null($homepage->translation('cs'));
	Assert::false($homepage->isHomepage(1));
});

test('a setting pointing to a missing page means no homepage', function () use ($homepage, $settings, $reset): void {
	$reset();
	$settings->set('homepage', '999');
	Assert::null($homepage->pageId());
	Assert::false($homepage->isHomepage(999));
});

test('a configured existing page is the homepage', function () use ($homepage, $settings, $pages, $reset): void {
	$reset();
	$id = $pages->createPage(null, 0, true);
	$other = $pages->createPage(null, 1, true);
	$settings->set('homepage', (string) $id);
	Assert::same($id, $homepage->pageId());
	Assert::true($homepage->isHomepage($id));
	Assert::false($homepage->isHomepage($other));
});

test('only a published translation is served', function () use ($homepage, $settings, $pages, $translate, $reset): void {
	$reset();
	$id = $pages->createPage(null, 0, true);
	$settings->set('homepage', (string) $id);
	$translate($id, 'cs', PublicationStatus::Published);
	$translate($id, 'en', PublicationStatus::Draft);

	Assert::same($id, $homepage->translation('cs')?->pageId);
	Assert::null($homepage->translation('en'));
	Assert::null($homepage->translation('de'));
});

foreach (['', 'abc', '0', '-1', '1.5', ' 1', '1 ', '01', '99999999999999999999'] as $bad) {
	test('malformed stored value ' . var_export($bad, true) . ' fails loudly', function () use ($homepage, $settings, $reset, $bad): void {
		$reset();
		$settings->set('homepage', $bad);
		Assert::exception(fn () => $homepage->pageId(), UnexpectedValueException::class);
		Assert::exception(fn () => $homepage->translation('cs'), UnexpectedValueException::class);
		Assert::exception(fn () => $homepage->isHomepage(1), UnexpectedValueException::class);
	});
}
