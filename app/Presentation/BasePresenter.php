<?php

declare(strict_types=1);

namespace App\Presentation;

use App\Core\Localization\CurrentLocale;
use Nette\Application\UI\Presenter;
use Nette\DI\Attributes\Inject;

abstract class BasePresenter extends Presenter
{
	#[Inject]
	public CurrentLocale $currentLocale;


	protected function beforeRender(): void
	{
		$this->template->locale = $this->currentLocale->get();
	}
}
