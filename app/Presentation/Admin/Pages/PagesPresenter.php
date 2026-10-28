<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Pages;

use App\Core\Security\Resource;
use App\Model\Content\InvalidContentException;
use App\Model\InvalidSlugException;
use App\Model\Page\CreatePage;
use App\Model\Page\Homepage;
use App\Model\Page\HomepageProtectedException;
use App\Model\Page\InvalidParentException;
use App\Model\Page\Page;
use App\Model\Page\PageHasChildrenException;
use App\Model\Page\PageRepository;
use App\Model\Page\PageService;
use App\Model\Page\PageTreeItem;
use App\Model\Page\SavePageTranslation;
use App\Model\Page\StaleVersionException;
use App\Model\Page\UpdatePage;
use App\Model\PublicationStatus;
use App\Presentation\Admin\AdminPresenter;
use App\Presentation\Forms\FormFactory;
use InvalidArgumentException;
use Nette\Application\UI\Form;
use Nette\Application\UI\Multiplier;
use Nette\DI\Attributes\Inject;
use Nette\Forms\Controls\BaseControl;
use Nette\Forms\Controls\SelectBox;
use Nette\Forms\Controls\SubmitButton;
use Nette\Localization\Translator;

final class PagesPresenter extends AdminPresenter
{
	protected const Resource RESOURCE = Resource::Pages;

	/** Mirrors the limits of PageService. */
	private const TITLE_MAX_LENGTH = 200;
	private const META_DESCRIPTION_MAX_LENGTH = 300;
	private const VERSION_PATTERN = '[1-9][0-9]*';

	/** Top-level item of the new page dropdown; its empty prompt is the placeholder of a refused parent. */
	private const TOP_LEVEL = 0;

	#[Inject]
	public PageRepository $pages;

	#[Inject]
	public PageService $pageService;

	#[Inject]
	public Homepage $homepage;

	#[Inject]
	public FormFactory $formFactory;

	#[Inject]
	public Translator $translator;

	/** The page of the detail; set in actionEdit(). */
	private Page $page;

	/** Name of the submitted form when the page was deleted before the save; only that form is rendered. */
	private ?string $deletedForm = null;

	/** Language of the translation tab. */
	private string $lang;

	/** Content of a preview submission, rendered below the form. */
	private ?string $preview = null;

	/** @var ?list<PageTreeItem> */
	private ?array $tree = null;


	public function renderDefault(): void
	{
		$tree = $this->tree();
		$this->template->tree = $tree;
		$this->template->movable = self::movable(...$tree);
		$this->template->homepageId = $this->homepage->pageId();
	}


	public function actionEdit(int $id, ?string $lang = null): void
	{
		$this->lang = $lang ?? $this->cmsConfig->defaultLocale;
		if (!in_array($this->lang, $this->cmsConfig->locales, true)) {
			$this->error();
		}
		$this->page = $this->pages->getPage($id) ?? $this->deletedMeanwhile($id);

		// Defaults apply only to a form that was not submitted, so a refused or restored save keeps what was sent.
		$this['properties']->setDefaults([
			'parent' => $this->page->parentId,
			'inMenu' => $this->page->inMenu,
			'version' => (string) $this->page->version,
		]);
		$translation = $this->pages->findTranslation($this->page->id, $this->lang);
		$this['translation']->setDefaults($translation === null ? ['status' => PublicationStatus::Draft->value] : [
			'title' => $translation->title,
			'slug' => $translation->slug,
			'content' => $translation->content,
			'metaDescription' => $translation->metaDescription,
			'status' => $translation->status->value,
			'version' => (string) $translation->version,
		]);
		$this->template->translation = $translation;
	}


	public function renderEdit(): void
	{
		// Narrowed only for rendering: a forged parent must still reach the service, which refuses it under the tree lock.
		$excluded = [$this->page->id, ...$this->pageService->descendantIds($this->page->id)];
		$parent = $this['properties']['parent'];
		assert($parent instanceof SelectBox);
		$parent->setItems(array_diff_key($parent->getItems(), array_flip($excluded)));
		// A refused parent cannot be shown; the prompt (top level) would then look selected and the next save would move the page there.
		if ($parent->getValue() === null && $parent->getRawValue() !== null && $parent->getRawValue() !== '') {
			$parent->setValue($this->page->parentId);
		}

		$inMenu = self::control($this['properties'], 'inMenu')->getValue();
		$parentId = $parent->getValue();
		$this->template->menuHiddenByParent = $inMenu === true && $this->hiddenAncestor(is_int($parentId) ? $parentId : null);

		$isHomepage = $this->homepage->isHomepage($this->page->id);
		$this->template->page = $this->page;
		$this->template->title = $this->pages->findTranslation($this->page->id, $this->cmsConfig->defaultLocale)?->title;
		$this->template->lang = $this->lang;
		$this->template->tabs = count($this->cmsConfig->locales) > 1 ? $this->cmsConfig->locales : [];
		$this->template->defaultLocale = $this->cmsConfig->defaultLocale;
		$this->template->isHomepage = $isHomepage;
		$this->template->missingLocales = $isHomepage ? $this->homepage->missingLocales() : [];
		$this->template->preview = $this->preview;
		$this->template->deletedForm = $this->deletedForm;
	}


	public function actionDelete(int $id): void
	{
		$this->page = $this->pages->getPage($id) ?? $this->error();
	}


	public function renderDelete(): void
	{
		$this->template->page = $this->page;
		$this->template->title = $this->pages->findTranslation($this->page->id, $this->cmsConfig->defaultLocale)->title
			?? $this->translator->translate('admin.pages.missing');
		$this->template->reason = $this->deleteRefusal();
	}


	/**
	 * One ↑/↓ form per page; the component name carries the page id, a malformed one is 404.
	 *
	 * @return Multiplier<Form>
	 */
	protected function createComponentMove(): Multiplier
	{
		$this->requireAction('default');
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


	protected function createComponentProperties(): Form
	{
		$this->requireAction('edit');
		$form = $this->formFactory->create();
		$this->addParentFields($form, newPage: false);
		$form->addHidden('version')
			->setRequired('admin.pages.stale')
			->addRule(Form::Pattern, 'admin.pages.stale', self::VERSION_PATTERN);
		$form->addSubmit('save', 'admin.pages.saveProperties');
		$form->setMappedType(PagePropertiesFormData::class);
		$form->onSuccess[] = $this->propertiesSucceeded(...);

		return $form;
	}


	protected function createComponentTranslation(): Form
	{
		$this->requireAction('edit');
		$form = $this->formFactory->create();
		$this->addTranslationFields($form);
		$form->addHidden('version')
			->addCondition(Form::Filled)
				->addRule(Form::Pattern, 'admin.pages.stale', self::VERSION_PATTERN);
		$form->addSubmit('save', 'admin.pages.saveTranslation');
		// The preview validates nothing: unfinished text can be previewed too.
		$form->addSubmit('preview', 'admin.pages.preview')
			->setValidationScope([]);
		$form->setMappedType(PageTranslationFormData::class);
		$form->onSuccess[] = $this->translationSucceeded(...);

		return $form;
	}


	protected function createComponentHomepage(): Form
	{
		$this->requireAction('edit');
		$form = $this->formFactory->create();
		$form->addSubmit('set', 'admin.pages.setHomepage');
		$form->onSuccess[] = $this->homepageSucceeded(...);

		return $form;
	}


	protected function createComponentDelete(): Form
	{
		$this->requireAction('delete');
		$form = $this->formFactory->create();
		$form->addSubmit('delete', 'admin.pages.delete');
		$form->onSuccess[] = $this->deleteSucceeded(...);

		return $form;
	}


	protected function createComponentNewPage(): Form
	{
		$this->requireAction('new');
		$form = $this->formFactory->create();
		$this->addParentFields($form, newPage: true);
		$this->addTranslationFields($form);
		$form->setDefaults(['parent' => self::TOP_LEVEL, 'inMenu' => true, 'status' => PublicationStatus::Draft->value]);
		$form->addSubmit('save', 'admin.pages.save');
		$form->onSuccess[] = $this->newPageSucceeded(...);

		return $form;
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


	private function propertiesSucceeded(Form $form): void
	{
		$data = $form->getValues(PagePropertiesFormData::class);
		$parentId = $this->submittedParent($form, $data->parent);
		if ($parentId === false) {
			return;
		}

		try {
			$this->pageService->updatePage(new UpdatePage($this->page->id, $parentId, $data->inMenu, (int) $data->version));
		} catch (StaleVersionException) {
			$form->addError('admin.pages.stale');

			return;
		} catch (InvalidParentException) {
			$this->refuseParent($form, 'admin.pages.invalidParent');

			return;
		} catch (InvalidSlugException $e) {
			// A recomputed descendant path collides; only a hierarchical path strategy recomputes paths.
			$form->addError('admin.pages.slug.' . $e->reason);

			return;
		} catch (InvalidArgumentException) {
			// The page is gone, or the chosen parent was deleted after the form was rendered.
			$this->pageOrNotFound();
			$this->refuseParent($form, 'admin.pages.parentGone');

			return;
		}

		$this->flashMessage('admin.pages.saved');
		$this->redirect('this');
	}


	private function translationSucceeded(Form $form): void
	{
		if ($form->isSubmitted() === $form['preview']) {
			$content = self::control($form, 'content')->getValue();
			$this->preview = is_string($content) ? $content : '';

			return;
		}

		$data = $form->getValues(PageTranslationFormData::class);
		try {
			$this->pageService->saveTranslation(new SavePageTranslation(
				$this->page->id,
				$this->lang,
				$data->title,
				$data->slug,
				$data->content,
				$data->metaDescription,
				$data->status,
				$data->version === '' ? null : (int) $data->version,
			));
		} catch (InvalidContentException $e) {
			$this->refuseContent($form, $e);

			return;
		} catch (InvalidSlugException $e) {
			self::control($form, 'slug')->addError('admin.pages.slug.' . $e->reason);

			return;
		} catch (StaleVersionException) {
			$form->addError('admin.pages.stale');

			return;
		} catch (HomepageProtectedException $e) {
			$form->addError(self::homepageMessage($e));

			return;
		} catch (InvalidArgumentException $e) {
			$this->pageOrNotFound();

			throw $e;
		}

		$this->flashMessage('admin.pages.saved');
		$this->redirect('this');
	}


	private function homepageSucceeded(Form $form): void
	{
		try {
			$this->pageService->setHomepage($this->page->id);
		} catch (HomepageProtectedException $e) {
			$form->addError(self::homepageMessage($e));

			return;
		} catch (InvalidArgumentException) {
			$this->error();
		}

		$this->flashMessage('admin.pages.homepageSet');
		$this->redirect('this');
	}


	private function deleteSucceeded(): void
	{
		try {
			$this->pageService->deletePage($this->page->id);
		} catch (HomepageProtectedException | PageHasChildrenException $e) {
			// A forged POST, or the page changed after the screen was rendered; the screen shows the reason again.
			$this->flashMessage($e instanceof HomepageProtectedException ? 'admin.pages.homepageProtected' : 'admin.pages.hasChildren');
			$this->redirect('this');
		} catch (InvalidArgumentException) {
			$this->error();
		}

		$this->flashMessage('admin.pages.deleted');
		$this->redirect('default');
	}


	/** Message key of the reason this page cannot be deleted, null when it can. */
	private function deleteRefusal(): ?string
	{
		if ($this->homepage->isHomepage($this->page->id)) {
			return 'admin.pages.homepageProtected';
		}

		return $this->pages->findChildren($this->page->id) === [] ? null : 'admin.pages.hasChildren';
	}


	private function newPageSucceeded(Form $form): void
	{
		$properties = $form->getValues(PagePropertiesFormData::class, [self::control($form, 'parent'), self::control($form, 'inMenu')]);
		$parentId = $this->submittedParent($form, $properties->parent);
		if ($parentId === false) {
			return;
		}

		$data = $form->getValues(PageTranslationFormData::class, array_map(
			static fn (string $name): BaseControl => self::control($form, $name),
			['title', 'slug', 'content', 'metaDescription', 'status'],
		));
		try {
			$translation = $this->pageService->createPage(new CreatePage(
				$parentId,
				$properties->inMenu,
				$this->cmsConfig->defaultLocale,
				$data->title,
				$data->slug,
				$data->content,
				$data->metaDescription,
				$data->status,
			));
		} catch (InvalidContentException $e) {
			$this->refuseContent($form, $e);

			return;
		} catch (InvalidSlugException $e) {
			self::control($form, 'slug')->addError('admin.pages.slug.' . $e->reason);

			return;
		} catch (InvalidArgumentException) {
			// The chosen parent was deleted after the form was rendered.
			$this->refuseParent($form, 'admin.pages.parentGone');

			return;
		}

		$this->flashMessage('admin.pages.created');
		$this->redirect('edit', ['id' => $translation->pageId, 'lang' => null]);
	}


	/**
	 * Parent dropdown over every page, and the menu flag. The items are titles, not message keys.
	 * In the detail the prompt is the top level. A new page has no stored parent to fall back to after a refused one,
	 * so there the top level is an item and the prompt is an empty required placeholder: the parent must be chosen again.
	 */
	private function addParentFields(Form $form, bool $newPage): void
	{
		$items = [];
		foreach ($this->tree() as $item) {
			$items[$item->pageId] = str_repeat('— ', $item->depth) . ($item->title ?? $this->translator->translate('admin.pages.missing'));
		}
		$topLevel = $this->translator->translate('admin.pages.root');
		// The label goes through the form translator regardless; the control translator covers the items and the prompt.
		$parent = $form->addSelect('parent', 'admin.pages.parent', $newPage ? [self::TOP_LEVEL => $topLevel] + $items : $items)
			->setTranslator(null);
		if ($newPage) {
			// Not setRequired(): its rule would refuse a deleted parent as an invalid choice before submittedParent() names it.
			$parent->setPrompt('– ' . $this->translator->translate('admin.pages.parent') . ' –')
				// Rendered as `required` by the template; Nette would print its own isRequired() over an HTML attribute.
				->setOption('requiredInBrowser', true);
		} else {
			$parent->setPrompt($topLevel);
		}
		$form->addCheckbox('inMenu', 'admin.pages.inMenu');
	}


	private function addTranslationFields(Form $form): void
	{
		// A single-line input is trimmed with Strings::trim like in the service, so a title of spaces only is refused here.
		$form->addText('title', 'admin.pages.field.title')
			->setRequired('admin.pages.field.titleRequired')
			->addRule(Form::MaxLength, 'admin.pages.field.tooLong', self::TITLE_MAX_LENGTH);
		$form->addText('slug', 'admin.pages.field.slug');
		$form->addTextArea('content', 'admin.pages.field.content');
		$form->addTextArea('metaDescription', 'admin.pages.field.meta')
			->addRule(Form::MaxLength, 'admin.pages.field.tooLong', self::META_DESCRIPTION_MAX_LENGTH);
		$form->addSelect('status', 'admin.pages.field.status', [
			PublicationStatus::Draft->value => 'admin.pages.status.draft',
			PublicationStatus::Published->value => 'admin.pages.status.published',
		]);
	}


	/**
	 * The parent id for the service, or false after adding an error for a submitted id that is not a page;
	 * the dropdown alone would turn such an id into the top level without a word.
	 */
	private function submittedParent(Form $form, ?int $parentId): int|false|null
	{
		$parent = self::control($form, 'parent');
		assert($parent instanceof SelectBox);
		$raw = $parent->getRawValue();
		if ($this->getAction() === 'new' && ($raw === null || $raw === '')) {
			$this->refuseParent($form, FormFactory::INVALID_CHOICE);

			return false;
		}
		if ($parentId === null && $raw !== null && $raw !== '') {
			$this->refuseParent($form, 'admin.pages.parentGone');

			return false;
		}

		return $parentId === self::TOP_LEVEL ? null : $parentId;
	}


	/** An error of the dropdown; the detail falls back to the stored parent in renderEdit(), a new page clears the choice. */
	private function refuseParent(Form $form, string $message): void
	{
		$parent = self::control($form, 'parent');
		// The dropdown has no translator of its own (its items are titles).
		$parent->addError($this->translator->translate($message), translate: false);
		if ($this->getAction() === 'new') {
			$parent->setValue(null);
		}
	}


	/** One error per problem at the content field, in line order; the posted text stays in the form. */
	private function refuseContent(Form $form, InvalidContentException $e): void
	{
		$content = self::control($form, 'content');
		foreach ($e->problems as $problem) {
			// Parameters are translated here: the control would translate the key without them.
			$content->addError($this->translator->translate($problem->messageKey, $problem->parameters), translate: false);
		}
	}


	/** A form posted to another screen has no page or no new page to work on. */
	private function requireAction(string $action): void
	{
		if ($this->getAction() !== $action) {
			$this->error();
		}
	}


	/**
	 * A save of a page deleted after the form was rendered keeps the typed values with an error and writes nothing;
	 * any other request is 404. The returned page only carries the id for rendering.
	 */
	private function deletedMeanwhile(int $id): Page
	{
		foreach (['properties', 'translation', 'homepage'] as $name) {
			$form = $this->getComponent($name);
			if ($form->isSubmitted() !== false) {
				// An error before the signal skips validation and onSuccess, so nothing reaches the service.
				$form->addError('admin.pages.deletedMeanwhile');
				$this->deletedForm = $name;

				return new Page($id, null, 0, false, 1);
			}
		}
		$this->error();
	}


	private function pageOrNotFound(): void
	{
		if ($this->pages->getPage($this->page->id) === null) {
			$this->error();
		}
	}


	private static function control(Form $form, string $name): BaseControl
	{
		$control = $form->getComponent($name);
		assert($control instanceof BaseControl);

		return $control;
	}


	private static function homepageMessage(HomepageProtectedException $e): string
	{
		return $e->reason === HomepageProtectedException::NEEDS_PUBLISHED ? 'admin.pages.homepageNeedsPublished' : 'admin.pages.homepageProtected';
	}


	/** @return list<PageTreeItem> */
	private function tree(): array
	{
		return $this->tree ??= $this->pages->findTree($this->cmsConfig->defaultLocale);
	}


	/** Whether the page or an ancestor of it is left out of the menu, by the rule of PageRepository::findMenu() in the default locale. */
	private function hiddenAncestor(?int $pageId): bool
	{
		$byId = [];
		foreach ($this->tree() as $item) {
			$byId[$item->pageId] = $item;
		}
		$seen = [];
		while ($pageId !== null && isset($byId[$pageId]) && !isset($seen[$pageId])) {
			$seen[$pageId] = true;
			$item = $byId[$pageId];
			if (!$item->inMenu || $item->status !== PublicationStatus::Published) {
				return true;
			}
			$pageId = $item->parentId;
		}

		return false;
	}


	/**
	 * Which rows have a previous and a next sibling; ↑/↓ without one would change nothing.
	 * Variadic so that PageTreeItem is used in code: UnusedUsesSniff (ECS) ignores a use referenced only from docblocks.
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
