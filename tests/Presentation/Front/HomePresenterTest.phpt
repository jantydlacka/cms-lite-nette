<?php

declare(strict_types=1);

use Nette\Application\IPresenterFactory;
use Nette\Application\Request;
use Nette\Application\Responses\TextResponse;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';

test('home page renders a document with the default language', function () {
	$presenter = Tests\container()->getByType(IPresenterFactory::class)->createPresenter('Front:Home');
	$presenter->autoCanonicalize = false;

	$response = $presenter->run(new Request('Front:Home', 'GET', ['action' => 'default']));

	Assert::type(TextResponse::class, $response);
	Assert::contains('<html lang="cs">', (string) $response->getSource());
});
