<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Pages;

use App\Core\Security\Resource;
use App\Model\Page\Homepage;
use App\Model\Page\PageRepository;
use App\Model\Page\PageService;
use App\Model\Page\PageTreeItem;
use App\Presentation\Admin\AdminPresenter;
use App\Presentation\Forms\FormFactory;
use InvalidArgumentException;
use Nette\Application\UI\Form;
use Nette\Application\UI\Multiplier;
use Nette\DI\Attributes\Inject;
use Nette\Forms\Controls\SubmitButton;

final class PagesPresenter extends AdminPresenter
{
	protected const Resource RESOURCE = Resource::Pages;

	#[Inject]
	public PageRepository $pages;

	#[Inject]
	public PageService $pageService;

	#[Inject]
	public Homepage $homepage;

	#[Inject]
	public FormFactory $formFactory;


	public function renderDefault(): void
	{
		$tree = $this->pages->findTree($this->cmsConfig->defaultLocale);
		$this->template->tree = $tree;
		$this->template->movable = self::movable(...$tree);
		$this->template->homepageId = $this->homepage->pageId();
	}


	/**
	 * One ↑/↓ form per page; the component name carries the page id, a malformed one is 404.
	 *
	 * @return Multiplier<Form>
	 */
	protected function createComponentMove(): Multiplier
	{
		return new Multiplier(function (string $id): Form {
			if (preg_match('~^[1-9][0-9]*$~D', $id) !== 1) {
				$this->error();
			}

			$form = $this->formFactory->create();
			$form->addSubmit('up', 'admin.pages.up');
			$form->addSubmit('down', 'admin.pages.down');
			$form->onSuccess[] = fn (Form $form) => $this->moveSucceeded($form, (int) $id);

			return $form;
		});
	}


	private function moveSucceeded(Form $form, int $pageId): void
	{
		$button = $form->isSubmitted();
		if (!$button instanceof SubmitButton) {
			// Submitted without a button (no browser does that): there is no direction to apply.
			$this->redirect('default');
		}

		try {
			$button->getName() === 'up' ? $this->pageService->moveUp($pageId) : $this->pageService->moveDown($pageId);
		} catch (InvalidArgumentException) {
			$this->error();
		}

		$this->flashMessage('admin.pages.moved');
		$this->redirect('default');
	}


	/**
	 * Which rows have a previous and a next sibling; ↑/↓ without one would change nothing.
	 *
	 * @return array<int, array{up: bool, down: bool}> by page id
	 */
	private static function movable(PageTreeItem ...$tree): array
	{
		$siblings = [];
		foreach ($tree as $item) {
			$siblings[$item->parentId ?? 0][] = $item->pageId;
		}

		$movable = [];
		foreach ($siblings as $ids) {
			foreach ($ids as $index => $id) {
				$movable[$id] = ['up' => $index > 0, 'down' => $index < count($ids) - 1];
			}
		}

		return $movable;
	}
}
