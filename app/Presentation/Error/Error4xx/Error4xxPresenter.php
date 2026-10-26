<?php

declare(strict_types=1);

namespace App\Presentation\Error\Error4xx;

use App\Presentation\BasePresenter;
use Nette\Application\BadRequestException;
use Nette\Application\Request;

final class Error4xxPresenter extends BasePresenter
{
	protected function startup(): void
	{
		parent::startup();
		if (!$this->getRequest()->isMethod(Request::FORWARD)) {
			$this->error();
		}
	}


	public function renderDefault(BadRequestException $exception): void
	{
		$this->getHttpResponse()->setCode($exception->getHttpCode());
	}
}
