<?php

declare(strict_types=1);

use App\Core\Localization\MissingTranslationException;
use Latte\Loaders\StringLoader;
use Nette\Application\IPresenterFactory;
use Nette\Application\Request;
use Nette\Application\Responses\TextResponse;
use Nette\Application\UI\Presenter;
use Nette\Bridges\ApplicationLatte\LatteFactory;
use Tester\Assert;
use function Tests\container;
use function Tests\html;

require __DIR__ . '/../../bootstrap.php';

test('home page renders a document with the default language', function (): void {
	$presenter = container()->getByType(IPresenterFactory::class)->createPresenter('Front:Home');
	assert($presenter instanceof Presenter);
	$presenter->autoCanonicalize = false;

	$response = $presenter->run(new Request('Front:Home', 'GET', ['action' => 'default']));

	Assert::type(TextResponse::class, $response);
	assert($response instanceof TextResponse);
	Assert::contains('<html lang="cs">', html($response));
});


test('home page has site name, theme toggle and assets, with the mode script before the stylesheet', function (): void {
	$presenter = container()->getByType(IPresenterFactory::class)->createPresenter('Front:Home');
	assert($presenter instanceof Presenter);
	$presenter->autoCanonicalize = false;
	$response = $presenter->run(new Request('Front:Home', 'GET', ['action' => 'default']));
	assert($response instanceof TextResponse);
	$page = html($response);

	Assert::match('%A%data-testid="site-name"%a?%>CMS Lite</a>%A%', $page);
	Assert::match('%A%data-testid="theme-toggle" aria-label="Tmavý režim"%A%', $page);
	Assert::match('%A%/assets/front.css%A%', $page);
	Assert::match('%A%/assets/front.js%A%', $page);
	Assert::match('%A%<title>CMS Lite</title>%A%', $page);

	$script = strpos($page, "localStorage.getItem('cms-theme')");
	$css = strpos($page, '/assets/vendor/pico.min.css');
	Assert::true($script !== false && $css !== false && $script < $css);
});


test('a template with a missing translation key fails in tests', function (): void {
	$latte = container()->getByType(LatteFactory::class)->create();
	$latte->setLoader(new StringLoader(['t' => '{_front.no.such.key}']));

	Assert::exception(fn () => $latte->renderToString('t'), MissingTranslationException::class);
});
