<?php

declare(strict_types=1);

use Nette\Application\IPresenterFactory;
use Nette\Application\Request;
use Nette\Application\Responses\TextResponse;
use Nette\Http\IResponse;
use Tester\Assert;
use Tracy\ILogger;

require __DIR__ . '/../../bootstrap.php';

test('500 error page sets the status code, renders the message and logs the exception', function () {
	$container = Tests\container();
	$exception = new RuntimeException('boom');
	$presenter = $container->getByType(IPresenterFactory::class)->createPresenter('Error:Error5xx');

	$response = $presenter->run(new Request('Error:Error5xx', Request::FORWARD, ['exception' => $exception]));

	Assert::type(TextResponse::class, $response);
	Assert::same(500, $container->getByType(IResponse::class)->getCode());
	Assert::contains('Something went wrong', (string) $response->getSource());
	Assert::same([[$exception, ILogger::EXCEPTION]], $container->getService('tracy.logger')->records);
});
