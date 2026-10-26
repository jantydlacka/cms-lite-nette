<?php

declare(strict_types=1);

use Nette\Application\IPresenterFactory;
use Nette\Application\Request;
use Nette\Application\Responses\TextResponse;
use Nette\Application\UI\Presenter;
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
