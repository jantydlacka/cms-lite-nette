<?php

declare(strict_types=1);

namespace App\Presentation\Front\Home;

use App\Model\Content\ContentContext;
use App\Model\Content\ContentKind;
use App\Model\Page\PageTranslation;
use App\Presentation\Front\FrontPresenter;

final class HomePresenter extends FrontPresenter
{
	private PageTranslation $page;


	public function actionDefault(): void
	{
		$page = $this->homepage->translation($this->currentLocale->get());
		if ($page === null) {
			$this->error();
		}

		$this->page = $page;
		$this->currentPageId = $page->pageId;
	}


	public function renderDefault(): void
	{
		$this->template->page = $this->page;
		$this->template->contentContext = new ContentContext(ContentKind::Page, $this->page->pageId, $this->page->locale, false);
		$this->template->breadcrumbs = [];
	}
}
