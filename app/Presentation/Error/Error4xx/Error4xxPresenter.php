<?php

declare(strict_types=1);

namespace App\Presentation\Error\Error4xx;

use App\Core\Routing\LocaleStrategy;
use App\Presentation\BasePresenter;
use Nette\Application\BadRequestException;
use Nette\Application\Request;
use Nette\DI\Attributes\Inject;

/** Touches neither the database nor the session: it must render when either is the cause. */
final class Error4xxPresenter extends BasePresenter
{
	private const array TitleKeys = [403 => 'error.403.title', 404 => 'error.404.title'];

	#[Inject]
	public LocaleStrategy $localeStrategy;


	protected function startup(): void
	{
		$path = $this->getHttpRequest()->getUrl()->getRelativePath();
		if ($this->locale === '') {
			$this->locale = $this->localeStrategy->localeOfPath($path) ?? '';
		}

		parent::startup();
		if (!$this->getRequest()->isMethod(Request::FORWARD)) {
			$this->error();
		}

		if (explode('/', $path, 2)[0] === 'admin') {
			$this->setLayout(__DIR__ . '/../../Admin/@layout.latte');
		}
	}


	public function renderDefault(BadRequestException $exception): void
	{
		$code = $exception->getHttpCode();
		$this->getHttpResponse()->setCode($code);
		$this->template->titleKey = self::TitleKeys[$code] ?? 'error.4xx.title';
		$this->template->demoData = false;
	}
}
