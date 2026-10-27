<?php

declare(strict_types=1);

namespace App\Presentation\Forms;

use Nette\Application\UI\Form;
use Nette\Localization\Translator;

final class FormFactory
{
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

		return $form;
	}
}
