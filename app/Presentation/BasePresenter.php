<?php

declare(strict_types=1);

namespace App\Presentation;

use Nette\Application\UI\Presenter;

abstract class BasePresenter extends Presenter
{
	private string $locale = 'cs';


	public function setLocale(string $locale): void
	{
		$this->locale = $locale;
	}


	protected function beforeRender(): void
	{
		$this->template->locale = $this->locale;
	}
}
