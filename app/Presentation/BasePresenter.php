<?php

declare(strict_types=1);

namespace App\Presentation;

use App\Core\Localization\CurrentLocale;
use App\Model\CmsConfig;
use Nette\Application\Attributes\Persistent;
use Nette\Application\UI\Presenter;
use Nette\DI\Attributes\Inject;

abstract class BasePresenter extends Presenter
{
	/** Set by the router (an error page inherits it through the forward); empty when no route matched, then the default locale applies. */
	#[Persistent]
	public string $locale = '';

	#[Inject]
	public CurrentLocale $currentLocale;

	#[Inject]
	public CmsConfig $cmsConfig;


	protected function startup(): void
	{
		parent::startup();
		if ($this->locale === '') {
			$this->locale = $this->currentLocale->get();
		} elseif (in_array($this->locale, $this->cmsConfig->locales, true)) {
			$this->currentLocale->set($this->locale);
		} else {
			$this->error();
		}
	}


	protected function beforeRender(): void
	{
		$this->template->locale = $this->currentLocale->get();
		$this->template->siteName = $this->cmsConfig->siteName;
	}
}
