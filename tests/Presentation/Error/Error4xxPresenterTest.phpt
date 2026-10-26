<?php

declare(strict_types=1);

use Nette\Application\BadRequestException;
use Nette\Application\IPresenterFactory;
use Nette\Application\Request;
use Nette\Application\Responses\TextResponse;
use Nette\Http\IResponse;
use Tester\Assert;
use function Tests\container;
use function Tests\html;

require __DIR__ . '/../../bootstrap.php';

test('404 error page sets the status code and renders the message', function (): void {
	$container = container();
	$presenter = $container->getByType(IPresenterFactory::class)->createPresenter('Error:Error4xx');

	$response = $presenter->run(new Request('Error:Error4xx', Request::FORWARD, ['exception' => new BadRequestException('', 404)]));

	Assert::type(TextResponse::class, $response);
	assert($response instanceof TextResponse);
	Assert::same(404, $container->getByType(IResponse::class)->getCode());
	Assert::contains('Page not found', html($response));
});
