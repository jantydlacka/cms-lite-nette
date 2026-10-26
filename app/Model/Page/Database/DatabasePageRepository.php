<?php

declare(strict_types=1);

namespace App\Model\Page\Database;

use App\Model\Page\InvalidSlugException;
use App\Model\Page\MenuItem;
use App\Model\Page\Page;
use App\Model\Page\PageRepository;
use App\Model\Page\PageTranslation;
use App\Model\PublicationStatus;
use DateTimeImmutable;
use DateTimeZone;
use Nette\Database\Explorer;
use Nette\Database\Row;
use Nette\Database\Table\ActiveRow;
use Nette\Database\Table\Selection;
use Nette\Database\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;
use UnexpectedValueException;

final class DatabasePageRepository implements PageRepository
{
	private const FORMAT = 'Y-m-d H:i:s';
	private const PATH_KEY = 'uq_page_translation_path';


	public function __construct(
		private readonly Explorer $explorer,
		private readonly ClockInterface $clock,
	) {
	}


	public function findPublishedByPath(string $locale, string $path): ?PageTranslation
	{
		$row = $this->translations()
			->where('locale', $locale)
			->where('path', $path)
			->where('status', PublicationStatus::Published->value)
			->fetch();

		return $row === null ? null : $this->hydrateTranslation($row);
	}


	public function findTranslation(int $pageId, string $locale): ?PageTranslation
	{
		$row = $this->translations()
			->where('page_id', $pageId)
			->where('locale', $locale)
			->fetch();

		return $row === null ? null : $this->hydrateTranslation($row);
	}


	public function lockTranslation(int $pageId, string $locale): ?PageTranslation
	{
		$row = $this->explorer->query(
			'SELECT *, CAST(updated_at AS CHAR) AS updated_at_text FROM page_translation WHERE page_id = ? AND locale = ? FOR UPDATE',
			$pageId,
			$locale,
		)->fetch();

		return $row === null ? null : $this->hydrateTranslation($row);
	}


	public function getPage(int $pageId): ?Page
	{
		$row = $this->explorer->table('page')->get($pageId);

		return $row === null ? null : $this->hydratePage($row);
	}


	/** @return list<Page> */
	public function findChildren(int $pageId): array
	{
		$children = [];
		foreach ($this->explorer->table('page')->where('parent_id', $pageId)->order('position, id') as $row) {
			$children[] = $this->hydratePage($row);
		}

		return $children;
	}


	/** @return list<MenuItem> */
	public function findMenu(string $locale): array
	{
		$rows = $this->explorer->query(
			'SELECT p.id, p.parent_id, t.title, t.path
			FROM page p
			JOIN page_translation t ON t.page_id = p.id AND t.locale = ? AND t.status = ?
			WHERE p.in_menu = 1
			ORDER BY p.position, p.id',
			$locale,
			PublicationStatus::Published->value,
		)->fetchAll();

		$byParent = [];
		foreach ($rows as $row) {
			$parentId = $row->parent_id === null ? 0 : self::int($row->parent_id);
			$byParent[$parentId][] = ['id' => self::int($row->id), 'title' => self::string($row->title), 'path' => self::string($row->path)];
		}

		return $this->buildMenu($byParent, 0);
	}


	/** @return list<MenuItem> */
	public function findBreadcrumbs(int $pageId, string $locale): array
	{
		$trail = [];
		$currentId = $pageId;
		$seen = [];
		while ($currentId !== null) {
			if (isset($seen[$currentId])) {
				return [];
			}
			$seen[$currentId] = true;

			$page = $this->getPage($currentId);
			$translation = $this->findTranslation($currentId, $locale);
			if ($page === null || $translation === null || $translation->status !== PublicationStatus::Published) {
				return [];
			}
			array_unshift($trail, new MenuItem($currentId, $translation->title, $translation->path));
			$currentId = $page->parentId;
		}

		return $trail;
	}


	public function createPage(?int $parentId, int $position, bool $inMenu): int
	{
		$now = $this->now();
		$row = $this->explorer->table('page')->insert([
			'parent_id' => $parentId,
			'position' => $position,
			'in_menu' => $inMenu ? 1 : 0,
			'created_at' => $now,
			'updated_at' => $now,
		]);
		assert($row instanceof ActiveRow);

		return self::int($row->id);
	}


	public function saveTranslation(PageTranslation $translation): void
	{
		$now = $this->now();
		$values = [
			'title' => $translation->title,
			'slug' => $translation->slug,
			'path' => $translation->path,
			'content' => $translation->content,
			'meta_description' => $translation->metaDescription,
			'status' => $translation->status->value,
			'updated_at' => $now,
		];
		$existing = $this->explorer->table('page_translation')
			->where('page_id', $translation->pageId)
			->where('locale', $translation->locale);

		// Not ON DUPLICATE KEY UPDATE: a clash on the (locale, path) unique key would overwrite another page's row.
		try {
			if ($existing->count('*') > 0) {
				$existing->update($values);
			} else {
				$this->explorer->table('page_translation')->insert($values + [
					'page_id' => $translation->pageId,
					'locale' => $translation->locale,
					'created_at' => $now,
				]);
			}
		} catch (UniqueConstraintViolationException $e) {
			// Only the path key means "slug taken"; a clash on the primary key is a different problem and must surface.
			throw str_contains($e->getMessage(), self::PATH_KEY) ? new InvalidSlugException(InvalidSlugException::TAKEN, $e) : $e;
		}
	}


	public function isPathTaken(string $locale, string $path, ?int $exceptPageId): bool
	{
		$selection = $this->explorer->table('page_translation')
			->where('locale', $locale)
			->where('path', $path);
		if ($exceptPageId !== null) {
			$selection->where('page_id <> ?', $exceptPageId);
		}

		return $selection->count('*') > 0;
	}


	/**
	 * @param array<int, list<array{id: int, title: string, path: string}>> $byParent
	 * @return list<MenuItem>
	 */
	private function buildMenu(array $byParent, int $parentId): array
	{
		$items = [];
		foreach ($byParent[$parentId] ?? [] as $row) {
			$items[] = new MenuItem($row['id'], $row['title'], $row['path'], $this->buildMenu($byParent, $row['id']));
		}

		return $items;
	}


	private function hydratePage(ActiveRow $row): Page
	{
		return new Page(
			self::int($row->id),
			$row->parent_id === null ? null : self::int($row->parent_id),
			self::int($row->position),
			self::int($row->in_menu) === 1,
		);
	}


	private function hydrateTranslation(ActiveRow|Row $row): PageTranslation
	{
		return new PageTranslation(
			self::int($row->page_id),
			self::string($row->locale),
			self::string($row->title),
			self::string($row->slug),
			self::string($row->path),
			self::string($row->content),
			self::string($row->meta_description),
			PublicationStatus::from(self::string($row->status)),
			$this->readTime($row->updated_at_text),
		);
	}


	private function now(): string
	{
		return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format(self::FORMAT);
	}


	/**
	 * DATETIME is selected as text: the driver would build a DateTime in the PHP default zone and shift values that zone skips.
	 *
	 * @return Selection<ActiveRow>
	 */
	private function translations(): Selection
	{
		return $this->explorer->table('page_translation')
			->select("page_translation.*, CAST(updated_at AS CHAR) AS updated_at_text");
	}


	private function readTime(mixed $text): DateTimeImmutable
	{
		$time = is_string($text) ? DateTimeImmutable::createFromFormat('!' . self::FORMAT, $text, new DateTimeZone('UTC')) : false;
		if ($time === false) {
			throw new UnexpectedValueException('Stored timestamp is not a valid UTC datetime.');
		}

		return $time;
	}


	private static function int(mixed $value): int
	{
		assert(is_int($value) || is_string($value));

		return (int) $value;
	}


	private static function string(mixed $value): string
	{
		assert(is_string($value));

		return $value;
	}
}
