<?php

declare(strict_types=1);

use App\Core\Localization\MissingTranslationException;
use App\Model\Page\PageRepository;
use App\Model\Page\PageService;
use App\Model\Page\SavePageTranslation;
use App\Model\PublicationStatus;
use App\Model\Setting\SettingRepository;
use Latte\Loaders\StringLoader;
use Nette\Bridges\ApplicationLatte\LatteFactory;
use Tester\Assert;
use Tests\Support\HttpApplication;
use function Tests\container;

require __DIR__ . '/../../bootstrap.php';

test('home page has site name, theme toggle and assets, with the mode script before the stylesheet', function (): void {
	$app = HttpApplication::boot();
	$id = $app->container->getByType(PageRepository::class)->createPage(null, 0, true);
	$app->container->getByType(PageService::class)->saveTranslation(new SavePageTranslation($id, 'cs', 'Úvod', 'uvod', 'Text', '', PublicationStatus::Published, null));
	$app->container->getByType(SettingRepository::class)->set('homepage', (string) $id);

	$result = $app->get('/');
	$page = $result->body;

	Assert::same(200, $result->code);
	Assert::contains('<html lang="cs">', $page);
	Assert::match('%A%data-testid="site-name"%a?%>CMS Lite</a>%A%', $page);
	Assert::match('%A%data-testid="theme-toggle" aria-label="Tmavý režim"%A%', $page);
	Assert::match('%A%/assets/front.css%A%', $page);
	Assert::match('%A%/assets/front.js%A%', $page);
	Assert::match('%A%<title>CMS Lite</title>%A%', $page);

	$script = strpos($page, "localStorage.getItem('cms-theme')");
	$css = strpos($page, '/assets/vendor/pico.min.css');
	Assert::true($script !== false && $css !== false && $script < $css);
});


test('without a homepage / is not found', function (): void {
	$result = HttpApplication::boot()->get('/');

	Assert::same(404, $result->code);
	Assert::same('Stránka nenalezena', $result->text('error-title'));
});


test('a template with a missing translation key fails in tests', function (): void {
	$latte = container()->getByType(LatteFactory::class)->create();
	$latte->setLoader(new StringLoader(['t' => '{_front.no.such.key}']));

	Assert::exception(fn () => $latte->renderToString('t'), MissingTranslationException::class);
});
