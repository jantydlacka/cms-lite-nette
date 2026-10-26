<?php

declare(strict_types=1);

namespace App\Presentation\Front;

use App\Model\Page\Homepage;
use App\Model\Page\PageRepository;
use App\Presentation\BasePresenter;
use Nette\DI\Attributes\Inject;

/** Public pages with the menu; error presenters stay outside, so they render without the database. */
abstract class FrontPresenter extends BasePresenter
{
	#[Inject]
	public PageRepository $pages;

	#[Inject]
	public Homepage $homepage;

	/** Marks the menu item of the rendered page. */
	protected ?int $currentPageId = null;


	protected function startup(): void
	{
		parent::startup();
		// Before the content lookup, so that `/en` and `/o-nas/` redirect even when nothing would be found there.
		$this->canonicalize();
	}


	protected function beforeRender(): void
	{
		parent::beforeRender();
		$locale = $this->currentLocale->get();
		$this->template->menu = $this->pages->findMenu($locale);
		$this->template->homepageId = $this->homepage->pageId();
		$this->template->currentPageId = $this->currentPageId;
	}
}
