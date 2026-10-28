<?php

declare(strict_types=1);

namespace App\Presentation\Admin\News;

use App\Core\Localization\SiteTime;
use App\Core\Security\Resource;
use App\Model\Content\ContentContext;
use App\Model\Content\ContentKind;
use App\Model\Content\InvalidContentException;
use App\Model\InvalidSlugException;
use App\Model\News\CreateNews;
use App\Model\News\News;
use App\Model\News\NewsRepository;
use App\Model\News\NewsService;
use App\Model\News\NewsState;
use App\Model\News\NewsTranslation;
use App\Model\News\SaveNewsTranslation;
use App\Model\News\StaleVersionException;
use App\Model\News\UpdateNews;
use App\Model\PublicationStatus;
use App\Model\User\UserRepository;
use App\Presentation\Admin\AdminPresenter;
use App\Presentation\Forms\FormFactory;
use DateTimeImmutable;
use InvalidArgumentException;
use Nette\Application\UI\Form;
use Nette\DI\Attributes\Inject;
use Nette\Forms\Control;
use Nette\Forms\Controls\BaseControl;
use Nette\Localization\Translator;
use Nette\Utils\Paginator;
use Nette\Utils\Strings;
use Psr\Clock\ClockInterface;
use UnexpectedValueException;

final class NewsPresenter extends AdminPresenter
{
	protected const Resource RESOURCE = Resource::News;

	private const ITEMS_PER_PAGE = 20;

	/** The route pattern does not filter a query value. */
	private const PAGE_PATTERN = '~^[1-9][0-9]*$~D';

	/** Mirror the limits of NewsService. */
	private const TITLE_MAX_LENGTH = 200;
	private const PEREX_MAX_LENGTH = 300;
	private const META_DESCRIPTION_MAX_LENGTH = 300;
	private const VERSION_PATTERN = '[1-9][0-9]*';

	/** Value of a datetime-local field; the seconds are dropped. */
	private const DATE_TIME_PATTERN = '~^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}(?::[0-9]{2}(?:\.[0-9]+)?)?$~D';

	/** Years a DATETIME column stores. */
	private const MIN_YEAR = 1000;
	private const MAX_YEAR = 9999;

	#[Inject]
	public NewsRepository $news;

	#[Inject]
	public UserRepository $users;

	#[Inject]
	public ClockInterface $clock;

	#[Inject]
	public NewsService $newsService;

	#[Inject]
	public SiteTime $siteTime;

	#[Inject]
	public FormFactory $formFactory;

	#[Inject]
	public Translator $translator;

	/** @var list<NewsRow> */
	private array $rows = [];

	private Paginator $paginator;

	/** The news item of the detail or of the confirmation; set in actionEdit() and actionDelete(). */
	private News $item;

	/** Name of the submitted form when the item was deleted before the save; only that form is rendered. */
	private ?string $deletedForm = null;

	/** Language of the translation tab. */
	private string $lang;

	private ?NewsTranslation $translation = null;

	/** Content of a preview submission, rendered below the form. */
	private ?string $preview = null;


	protected function startup(): void
	{
		// `01` would otherwise canonicalize to another URL instead of 404.
		if ($this->getAction() === 'default') {
			$page = $this->getParameter('page');
			if ($page !== 1 && !(is_string($page) && preg_match(self::PAGE_PATTERN, $page) === 1)) {
				$this->error();
			}
		}

		parent::startup();
	}


	public function actionDefault(int $page = 1): void
	{
		$paginator = new Paginator();
		$paginator->setItemsPerPage(self::ITEMS_PER_PAGE);
		$paginator->setItemCount($this->news->countAll());
		// Paginator would clamp an out-of-range page silently.
		if ($page > $paginator->getLastPage()) {
			$this->error();
		}

		$paginator->setPage($page);
		$this->paginator = $paginator;

		$now = $this->clock->now();
		$authors = [];
		$this->rows = [];
		foreach ($this->news->findAll($paginator->getItemsPerPage(), $paginator->getOffset()) as $news) {
			$translations = $this->news->findTranslations($news->id);
			$title = ($translations[$this->cmsConfig->defaultLocale] ?? ($translations === [] ? null : reset($translations)))?->title;
			if ($news->authorId !== null && !array_key_exists($news->authorId, $authors)) {
				$authors[$news->authorId] = $this->users->findById($news->authorId)?->name;
			}

			$states = [];
			foreach ($this->cmsConfig->locales as $locale) {
				$states[$locale] = NewsState::of($news, $translations[$locale] ?? null, $now);
			}

			$this->rows[] = new NewsRow($news, $title, $news->authorId === null ? null : $authors[$news->authorId], $states);
		}
	}


	public function renderDefault(): void
	{
		$this->template->rows = $this->rows;
		$this->template->paginator = $this->paginator;
	}


	public function actionEdit(int $id, ?string $lang = null): void
	{
		$this->lang = $lang ?? $this->cmsConfig->defaultLocale;
		if (!in_array($this->lang, $this->cmsConfig->locales, true)) {
			$this->error();
		}
		$this->item = $this->news->getNews($id) ?? $this->deletedMeanwhile($id);

		// Defaults apply only to a form that was not submitted, so a refused or restored save keeps what was sent.
		$this['properties']->setDefaults([
			'publishedAt' => $this->item->publishedAt === null ? null : $this->siteTime->toSite($this->item->publishedAt),
			'version' => (string) $this->item->version,
		]);
		$this->translation = $this->news->findTranslation($this->item->id, $this->lang);
		$this['translation']->setDefaults($this->translation === null ? ['status' => PublicationStatus::Draft->value] : [
			'title' => $this->translation->title,
			'slug' => $this->translation->slug,
			'perex' => $this->translation->perex,
			'content' => $this->translation->content,
			'metaDescription' => $this->translation->metaDescription,
			'status' => $this->translation->status->value,
			'version' => (string) $this->translation->version,
		]);
	}


	public function renderEdit(): void
	{
		$this->template->item = $this->item;
		$this->template->title = $this->news->findTranslation($this->item->id, $this->cmsConfig->defaultLocale)?->title;
		$this->template->author = $this->item->authorId === null ? null : $this->users->findById($this->item->authorId)?->name;
		$this->template->translation = $this->translation;
		$this->template->scheduledAt = NewsState::of($this->item, $this->translation, $this->clock->now()) === NewsState::Scheduled
			? $this->item->publishedAt
			: null;
		$this->template->lang = $this->lang;
		$this->template->tabs = count($this->cmsConfig->locales) > 1 ? $this->cmsConfig->locales : [];
		$this->template->defaultLocale = $this->cmsConfig->defaultLocale;
		$this->template->timezone = $this->cmsConfig->timezone;
		$this->template->preview = $this->preview;
		$this->template->previewContext = new ContentContext(ContentKind::News, $this->item->id, $this->lang, true);
		$this->template->deletedForm = $this->deletedForm;
	}


	public function renderNew(): void
	{
		$this->template->timezone = $this->cmsConfig->timezone;
	}


	public function actionDelete(int $id): void
	{
		$this->item = $this->news->getNews($id) ?? $this->error();
	}


	public function renderDelete(): void
	{
		$this->template->item = $this->item;
		$translation = $this->news->findTranslation($this->item->id, $this->cmsConfig->defaultLocale);
		$this->template->title = $translation !== null ? $translation->title : $this->translator->translate('admin.pages.missing');
	}


	protected function createComponentNewNews(): Form
	{
		$this->requireAction('new');
		$form = $this->formFactory->create();
		$this->addPublishedAtField($form);
		$this->addTranslationFields($form);
		$form->setDefaults(['status' => PublicationStatus::Draft->value]);
		$form->addSubmit('save', 'admin.pages.save');
		$form->onSuccess[] = $this->newNewsSucceeded(...);

		return $form;
	}


	protected function createComponentProperties(): Form
	{
		$this->requireAction('edit');
		$form = $this->formFactory->create();
		$this->addPublishedAtField($form);
		$form->addHidden('version')
			->setRequired('admin.news.stale')
			->addRule(Form::Pattern, 'admin.news.stale', self::VERSION_PATTERN);
		$form->addSubmit('save', 'admin.news.saveProperties');
		$form->setMappedType(NewsPropertiesFormData::class);
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
				->addRule(Form::Pattern, 'admin.news.stale', self::VERSION_PATTERN);
		$form->addSubmit('save', 'admin.news.saveTranslation');
		// The preview validates nothing: unfinished text can be previewed too.
		$form->addSubmit('preview', 'admin.pages.preview')
			->setValidationScope([]);
		$form->setMappedType(NewsTranslationFormData::class);
		$form->onSuccess[] = $this->translationSucceeded(...);

		return $form;
	}


	protected function createComponentDelete(): Form
	{
		$this->requireAction('delete');
		$form = $this->formFactory->create();
		$form->addSubmit('delete', 'admin.news.delete');
		$form->onSuccess[] = $this->deleteSucceeded(...);

		return $form;
	}


	private function newNewsSucceeded(Form $form): void
	{
		$entered = $form->getValues(NewsPropertiesFormData::class, [self::control($form, 'publishedAt')])->publishedAt;
		$data = $form->getValues(NewsTranslationFormData::class, array_map(
			static fn (string $name): BaseControl => self::control($form, $name),
			['title', 'slug', 'perex', 'content', 'metaDescription', 'status'],
		));
		$userId = $this->getUser()->getId();
		$publishedAt = $entered === null ? null : $this->siteTime->fromInput($entered);
		try {
			$translation = $this->newsService->createNews(new CreateNews(
				$publishedAt,
				is_int($userId) ? $userId : throw new UnexpectedValueException('The signed-in identity has no integer id.'),
				$this->cmsConfig->defaultLocale,
				$data->title,
				$data->slug,
				$data->perex,
				$data->content,
				$data->metaDescription,
				$data->status,
			));
		} catch (InvalidContentException $e) {
			$this->refuseContent($form, $e);

			return;
		} catch (InvalidSlugException $e) {
			self::control($form, 'slug')->addError(self::slugMessage($e));

			return;
		}

		$this->flashMessage('admin.news.created');
		$this->reportShift($entered, $publishedAt);
		$this->redirect('edit', ['id' => $translation->newsId, 'lang' => null]);
	}


	private function propertiesSucceeded(Form $form): void
	{
		$data = $form->getValues(NewsPropertiesFormData::class);
		$publishedAt = $data->publishedAt === null ? null : $this->siteTime->fromInput($data->publishedAt);
		try {
			$this->newsService->updateNews(new UpdateNews($this->item->id, $publishedAt, (int) $data->version));
		} catch (StaleVersionException) {
			$form->addError('admin.news.stale');

			return;
		} catch (InvalidArgumentException) {
			// Deleted after the action read it.
			$this->refuseDeleted($form, 'properties');

			return;
		}

		$this->flashMessage('admin.news.saved');
		$this->reportShift($data->publishedAt, $publishedAt);
		$this->redirect('this');
	}


	private function translationSucceeded(Form $form): void
	{
		if ($form->isSubmitted() === $form['preview']) {
			$content = self::control($form, 'content')->getValue();
			$this->preview = is_string($content) ? $content : '';

			return;
		}

		$data = $form->getValues(NewsTranslationFormData::class);
		try {
			$this->newsService->saveTranslation(new SaveNewsTranslation(
				$this->item->id,
				$this->lang,
				$data->title,
				$data->slug,
				$data->perex,
				$data->content,
				$data->metaDescription,
				$data->status,
				$data->version === '' ? null : (int) $data->version,
			));
		} catch (InvalidContentException $e) {
			$this->refuseContent($form, $e);

			return;
		} catch (InvalidSlugException $e) {
			self::control($form, 'slug')->addError(self::slugMessage($e));

			return;
		} catch (StaleVersionException) {
			$form->addError('admin.news.stale');

			return;
		} catch (InvalidArgumentException $e) {
			if ($this->news->getNews($this->item->id) !== null) {
				throw $e;
			}
			$this->refuseDeleted($form, 'translation');

			return;
		}

		$this->flashMessage('admin.news.saved');
		$this->redirect('this');
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


	private function deleteSucceeded(): void
	{
		try {
			$this->newsService->deleteNews($this->item->id);
		} catch (InvalidArgumentException) {
			$this->error();
		}

		$this->flashMessage('admin.news.deleted');
		$this->redirect('default');
	}


	/**
	 * Empty means no date (the service dates the item when it is published).
	 * The control turns a value it cannot parse into null, which would drop the typed date without a word, and it parses
	 * anything PHP does (`tomorrow`, `99999-01-01T10:00` as 2009); only what a datetime-local field sends is accepted.
	 */
	private function addPublishedAtField(Form $form): void
	{
		$form->addDateTime('publishedAt', 'admin.news.field.publishedAt', withSeconds: false);
		$form->onValidate[] = function (Form $form): void {
			$control = self::control($form, 'publishedAt');
			$raw = $form->getHttpData(Form::DataLine, $control->getHtmlName());
			if (!is_string($raw) || $raw === '') {
				return;
			}
			$value = $control->getValue();
			$year = $value instanceof DateTimeImmutable ? (int) $this->siteTime->fromInput($value)->format('Y') : 0;
			if (
				!$value instanceof DateTimeImmutable
				|| preg_match(self::DATE_TIME_PATTERN, $raw) !== 1
				|| $value->format('Y-m-d\TH:i') !== substr($raw, 0, 16)
				|| $year < self::MIN_YEAR
				|| $year > self::MAX_YEAR
			) {
				$control->addError('admin.news.field.invalidDate');
				// The re-rendered field must not offer the loose parse for the next save; only what was typed, or nothing.
				if (!$value instanceof DateTimeImmutable || $value->format('Y-m-d\TH:i') !== $raw) {
					$control->setValue(null);
				}
			}
		};
	}


	private function addTranslationFields(Form $form): void
	{
		// A single-line input is trimmed with Strings::trim like in the service, so a title of spaces only is refused here.
		$form->addText('title', 'admin.pages.field.title')
			->setRequired('admin.pages.field.titleRequired')
			->addRule(Form::MaxLength, 'admin.pages.field.tooLong', self::TITLE_MAX_LENGTH);
		$form->addText('slug', 'admin.pages.field.slug');
		// A textarea is not trimmed by the form; the service trims it, so the limits apply to the trimmed text.
		$form->addTextArea('perex', 'admin.news.field.perex')
			->setRequired('admin.news.field.perexRequired')
			->addFilter(static fn (mixed $perex): string => is_string($perex) ? Strings::trim($perex) : '')
			->addRule(static fn (Control $perex): bool => $perex->getValue() !== '', 'admin.news.field.perexRequired')
			->addRule(Form::MaxLength, 'admin.pages.field.tooLong', self::PEREX_MAX_LENGTH);
		$form->addTextArea('content', 'admin.pages.field.content');
		$form->addTextArea('metaDescription', 'admin.pages.field.meta')
			->addRule(Form::MaxLength, 'admin.pages.field.tooLong', self::META_DESCRIPTION_MAX_LENGTH);
		$form->addSelect('status', 'admin.pages.field.status', [
			PublicationStatus::Draft->value => 'admin.pages.status.draft',
			PublicationStatus::Published->value => 'admin.pages.status.published',
		]);
	}


	/** A local time skipped by the change to summer time is saved shifted; say so instead of changing it silently. */
	private function reportShift(?DateTimeImmutable $entered, ?DateTimeImmutable $saved): void
	{
		if ($entered === null || $saved === null) {
			return;
		}
		$local = $this->siteTime->toSite($saved);
		if ($local->format('Y-m-d H:i') !== $entered->format('Y-m-d H:i')) {
			$flash = $this->flashMessage('admin.news.timeShifted');
			$flash->parameters = ['entered' => $entered->format('H:i'), 'saved' => $local->format('H:i')];
		}
	}


	/** A form posted to another screen has no item or no new item to work on. */
	private function requireAction(string $action): void
	{
		if ($this->getAction() !== $action) {
			$this->error();
		}
	}


	/**
	 * A save of an item deleted after the form was rendered keeps the typed values with an error and writes nothing;
	 * any other request is 404. The returned item only carries the id for rendering.
	 */
	private function deletedMeanwhile(int $id): News
	{
		foreach (['properties', 'translation'] as $name) {
			$form = $this->getComponent($name);
			if ($form->isSubmitted() !== false) {
				// An error before the signal skips validation and onSuccess, so nothing reaches the service.
				$this->refuseDeleted($form, $name);

				return new News($id, null, null, 1, $this->clock->now());
			}
		}
		$this->error();
	}


	private function refuseDeleted(Form $form, string $name): void
	{
		$form->addError('admin.news.deletedMeanwhile');
		$this->deletedForm = $name;
	}


	/** A news slug can only clash with another news item (its path lies under the news segment); the format and length texts are shared with pages. */
	private static function slugMessage(InvalidSlugException $e): string
	{
		return $e->reason === InvalidSlugException::TAKEN ? 'admin.news.slug.taken' : 'admin.pages.slug.' . $e->reason;
	}


	private static function control(Form $form, string $name): BaseControl
	{
		$control = $form->getComponent($name);
		assert($control instanceof BaseControl);

		return $control;
	}
}
