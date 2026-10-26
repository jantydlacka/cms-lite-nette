<?php

declare(strict_types=1);

namespace App\Presentation\Error\Error5xx;

use App\Presentation\BasePresenter;
use Nette\Application\Request;
use Tracy\ILogger;

final class Error5xxPresenter extends BasePresenter
{
	public function __construct(
		private readonly ILogger $logger,
	) {
		parent::__construct();
	}


	protected function startup(): void
	{
		parent::startup();
		if (!$this->getRequest()?->isMethod(Request::FORWARD)) {
			$this->error();
		}
	}


	public function renderDefault(\Throwable $exception): void
	{
		$this->logger->log($exception, ILogger::EXCEPTION);
		$this->getHttpResponse()->setCode(500);
	}
}
