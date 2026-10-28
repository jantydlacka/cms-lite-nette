<?php

declare(strict_types=1);

use App\Core\Localization\CurrentLocale;
use App\Model\Content\ContentContext;
use App\Model\Content\ContentKind;
use App\Model\News\News;
use App\Model\News\NewsListComponent;
use App\Model\News\NewsListItem;
use App\Model\News\NewsTranslation;
use App\Model\PublicationStatus;
use App\Presentation\Components\LatteComponentRenderer;
use Nette\Application\Application;
use Nette\Application\IPresenterFactory;
use Nette\DI\Container;
use Tester\Assert;
use Tests\Support\FakeNewsRepository;
use Tests\Support\HttpApplication;

require __DIR__ . '/../../bootstrap.php';


/** A container with two languages whose application runs the homepage presenter, as during a request. */
function withPresenter(): Container
{
	$container = HttpApplication::boot([['cms' => ['locales' => ['cs', 'en']]]])->container;
	$application = $container->getByType(Application::class);
	$presenter = $container->getByType(IPresenterFactory::class)->createPresenter('Front:Home');
	(fn () => $this->presenter = $presenter)->call($application);

	return $container;
}


function newsItem(?DateTimeImmutable $publishedAt): NewsListItem
{
	$now = new DateTimeImmutable('2026-01-01 10:00:00');

	return new NewsListItem(
		new News(1, $publishedAt, null, 1, $now),
		new NewsTranslation(1, 'en', 'Title', 'title', 'Perex', 'Text.', '', PublicationStatus::Published, $now, 1),
	);
}


$container = withPresenter();


test('the locale of the request is back after a component rendered in the language of the content', function () use ($container): void {
	$locale = $container->getByType(CurrentLocale::class);
	Assert::same('cs', $locale->get());
	$renderer = $container->getByType(LatteComponentRenderer::class);
	$component = new NewsListComponent(new FakeNewsRepository());

	Assert::same('', $renderer->render($component, ['count' => 3], new ContentContext(ContentKind::Page, 1, 'en', false)));
	Assert::same('cs', $locale->get());
});


test('the locale of the request is back after a component threw', function () use ($container): void {
	$locale = $container->getByType(CurrentLocale::class);
	$locale->set('cs');
	$renderer = $container->getByType(LatteComponentRenderer::class);
	// The item template reads the date, which a visible item always has.
	$component = new NewsListComponent(new FakeNewsRepository([newsItem(null)]));

	Assert::exception(
		fn () => $renderer->render($component, ['count' => 3], new ContentContext(ContentKind::Page, 1, 'en', false)),
		Error::class,
	);
	Assert::same('cs', $locale->get());
});
