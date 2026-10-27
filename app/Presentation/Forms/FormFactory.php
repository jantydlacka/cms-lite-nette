<?php

declare(strict_types=1);

namespace App\Presentation\Forms;

use Nette\Application\UI\Form;
use Nette\Forms\Controls\SelectBox;
use Nette\Forms\Rules;
use Nette\Forms\Validator;
use Nette\Localization\Translator;

final class FormFactory
{
	public const INVALID_CHOICE = 'admin.form.invalidChoice';

	private readonly FormTranslator $translator;


	public function __construct(Translator $translator)
	{
		$this->translator = new FormTranslator($translator);
	}


	public function create(): Form
	{
		$form = new Form();
		$form->setTranslator($this->translator);
		// No CSRF token: UI\Form accepts only same-origin submissions, and a token would start the session while rendering.
		// Anchoring comes after the presenter added the controls and before validation and rendering.
		$form->onAnchor[] = static function (Form $form): void {
			foreach ($form->getControls() as $control) {
				if ($control instanceof SelectBox) {
					self::replaceValidMessage($control->getRules());
				}
			}
		};

		return $form;
	}


	/** SelectBox adds its validity rule with an English text, which is no message key; a forged value would break the translator. */
	private static function replaceValidMessage(Rules $rules): void
	{
		foreach ($rules as $rule) {
			if ($rule->branch !== null) {
				self::replaceValidMessage($rule->branch);
			} elseif ($rule->message === Validator::$messages[SelectBox::Valid]) {
				$rule->message = self::INVALID_CHOICE;
			}
		}
	}
}
