<?php

declare(strict_types=1);

namespace App\Core\Latte;

use App\Core\Localization\SiteTime;
use App\Model\Content\MarkdownRenderer;
use DateTimeImmutable;
use Latte\Extension;
use Latte\Runtime\Html;
use Nette\Localization\Translator;

final class CmsLatteExtension extends Extension
{
	public function __construct(
		private readonly MarkdownRenderer $markdown,
		private readonly Translator $translator,
		private readonly SiteTime $siteTime,
	) {
	}


	/** @return array<string, callable> */
	public function getFilters(): array
	{
		return [
			'markdown' => fn (string $markdown): Html => new Html($this->markdown->render($markdown)),
			'siteDate' => fn (DateTimeImmutable $moment): string => $this->format($moment, 'format.date'),
			'siteDateTime' => fn (DateTimeImmutable $moment): string => $this->format($moment, 'format.dateTime'),
		];
	}


	private function format(DateTimeImmutable $moment, string $key): string
	{
		// DateTime::format() always names months in English; only the format string is localised.
		return $this->siteTime->toSite($moment)->format((string) $this->translator->translate($key));
	}
}
