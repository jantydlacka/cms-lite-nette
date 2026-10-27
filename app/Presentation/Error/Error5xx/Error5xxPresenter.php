<?php

declare(strict_types=1);

namespace App\Presentation\Error\Error5xx;

use App\Presentation\Admin\AdminArea;
use App\Presentation\BasePresenter;
use Nette\Application\Request;
use Throwable;
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
		if (!$this->getRequest()->isMethod(Request::FORWARD)) {
			$this->error();
		}

		if (AdminArea::contains($this->getHttpRequest()->getUrl()->getRelativePath())) {
			$this->setLayout(AdminArea::Layout);
		}
	}


	public function renderDefault(Throwable $exception): void
	{
		$this->logger->log($exception, ILogger::EXCEPTION);
		$this->getHttpResponse()->setCode(500);
		$this->template->demoData = $this->cmsConfig->demoData;
	}
}
