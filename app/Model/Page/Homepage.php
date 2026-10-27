<?php

declare(strict_types=1);

namespace App\Model\Page;

use App\Model\CmsConfig;
use App\Model\PublicationStatus;
use App\Model\Setting\SettingRepository;
use UnexpectedValueException;

final class Homepage
{
	public const KEY = 'homepage';


	public function __construct(
		private readonly SettingRepository $settings,
		private readonly PageRepository $pages,
		private readonly CmsConfig $config,
	) {
	}


	/**
	 * Null when the setting is absent or points to a page that does not exist.
	 *
	 * @throws UnexpectedValueException when the stored value is not a positive integer
	 */
	public function pageId(): ?int
	{
		$value = $this->settings->get(self::KEY);
		if ($value === null) {
			return null;
		}

		$id = preg_match('~^[1-9][0-9]*$~', $value) === 1 ? (int) $value : 0;
		if ($id === 0 || (string) $id !== $value) {
			throw new UnexpectedValueException(sprintf('Setting "%s" must be a positive integer page id, got %s.', self::KEY, var_export($value, true)));
		}

		return $this->pages->getPage($id) === null ? null : $id;
	}


	/** Published translations only. */
	public function translation(string $locale): ?PageTranslation
	{
		$id = $this->pageId();
		if ($id === null) {
			return null;
		}

		$translation = $this->pages->findTranslation($id, $locale);

		return $translation?->status === PublicationStatus::Published ? $translation : null;
	}


	public function isHomepage(int $pageId): bool
	{
		return $this->pageId() === $pageId;
	}


	/**
	 * Configured locales, in configuration order, without a published homepage translation; empty when there is no homepage.
	 *
	 * @return list<string>
	 */
	public function missingLocales(): array
	{
		$id = $this->pageId();
		if ($id === null) {
			return [];
		}

		return array_values(array_filter(
			$this->config->locales,
			fn (string $locale): bool => $this->pages->findTranslation($id, $locale)?->status !== PublicationStatus::Published,
		));
	}
}
