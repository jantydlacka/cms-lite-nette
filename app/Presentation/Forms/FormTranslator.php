<?php

declare(strict_types=1);

namespace App\Presentation\Forms;

use Nette\Localization\Translator;
use Stringable;

/**
 * Nette Forms passes every rule message with the rule argument (null or e.g. a length), which the
 * application translator rejects. Forms fill `%d`, `%label` and `%value` themselves after translation,
 * so the argument is dropped; a plural message would fail loudly on its missing count.
 */
final class FormTranslator implements Translator
{
	public function __construct(
		private readonly Translator $translator,
	) {
	}


	public function translate(string|Stringable $message, mixed ...$parameters): string|Stringable
	{
		return $this->translator->translate($message);
	}
}
