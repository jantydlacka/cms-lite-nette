<?php

declare(strict_types=1);

namespace App\Presentation\Front\Page;

use App\Model\Content\ContentContext;
use App\Model\Content\ContentKind;
use App\Model\Page\PageTranslation;
use App\Model\PublicationStatus;
use App\Model\Redirect\PathRedirectRepository;
use App\Presentation\Front\FrontPresenter;

final class PagePresenter extends FrontPresenter
{
	private PageTranslation $page;


	public function __construct(
		private readonly PathRedirectRepository $redirects,
	) {
		parent::__construct();
	}


	public function actionDefault(string $path): void
	{
		$locale = $this->currentLocale->get();
		$page = $this->pages->findPublishedByPath($locale, $path);
		if ($page === null) {
			$this->redirectFromOldPath($locale, $path);
			$this->error();
		}

		if ($this->homepage->isHomepage($page->pageId)) {
			$this->redirectPermanent(':Front:Home:default');
		}

		$this->page = $page;
		$this->currentPageId = $page->pageId;
	}


	public function renderDefault(): void
	{
		$this->template->page = $this->page;
		$this->template->contentContext = new ContentContext(ContentKind::Page, $this->page->pageId, $this->page->locale, false);
		$this->template->breadcrumbs = $this->pages->findBreadcrumbs($this->page->pageId, $this->page->locale);
	}


	/** An old path leads to the current path of a published page; anything else falls through to 404. */
	private function redirectFromOldPath(string $locale, string $path): void
	{
		$target = $this->redirects->findTarget($locale, $path);
		if ($target === null || $target->type !== 'page') {
			return;
		}

		$page = $this->pages->findTranslation($target->id, $locale);
		if ($page === null || $page->status !== PublicationStatus::Published) {
			return;
		}

		if ($this->homepage->isHomepage($page->pageId)) {
			$this->redirectPermanent(':Front:Home:default');
		}

		$this->redirectPermanent('this', ['path' => $page->path]);
	}
}
