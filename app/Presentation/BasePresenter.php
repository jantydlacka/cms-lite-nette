<?php

declare(strict_types=1);

namespace App\Presentation;

use App\Core\Localization\CurrentLocale;
use App\Model\CmsConfig;
use Nette\Application\UI\Presenter;
use Nette\DI\Attributes\Inject;

abstract class BasePresenter extends Presenter
{
	#[Inject]
	public CurrentLocale $currentLocale;

	#[Inject]
	public CmsConfig $cmsConfig;


	protected function beforeRender(): void
	{
		$this->template->locale = $this->currentLocale->get();
		$this->template->siteName = $this->cmsConfig->siteName;
	}
}
