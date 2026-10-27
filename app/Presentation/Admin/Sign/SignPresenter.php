<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Sign;

use App\Core\Security\CmsAuthenticator;
use App\Presentation\BasePresenter;
use App\Presentation\Forms\FormFactory;
use Nette\Application\UI\Form;
use Nette\DI\Attributes\Inject;
use Nette\Security\AuthenticationException;

final class SignPresenter extends BasePresenter
{
	/** Key of the request stored before the redirect here, see AdminPresenter::startup(). */
	public string $backlink = '';

	#[Inject]
	public FormFactory $formFactory;


	public function actionIn(string $backlink = ''): void
	{
		if ($this->getUser()->isLoggedIn()) {
			$this->redirect(':Admin:Dashboard:default');
		}

		$this->backlink = $backlink;
	}


	protected function beforeRender(): void
	{
		parent::beforeRender();
		$this->template->demoData = $this->cmsConfig->demoData;
	}


	protected function createComponentSignInForm(): Form
	{
		$form = $this->formFactory->create();
		// Every malformed input gets the same message as wrong credentials.
		$form->addText('email', 'admin.sign.email')
			->setHtmlType('email')
			->setRequired('admin.sign.invalid')
			->addRule(Form::Email, 'admin.sign.invalid')
			->addRule(Form::MaxLength, 'admin.sign.invalid', 254);
		$form->addPassword('password', 'admin.sign.password')
			->setRequired('admin.sign.invalid');
		$form->addSubmit('send', 'admin.sign.submit');
		$form->setMappedType(SignInFormData::class);
		$form->onSuccess[] = $this->signInFormSucceeded(...);

		return $form;
	}


	private function signInFormSucceeded(Form $form): void
	{
		$data = $form->getValues(SignInFormData::class);
		try {
			$this->getUser()->login($data->email, $data->password);
		} catch (AuthenticationException $e) {
			$form->addError(match ($e->getCode()) {
				CmsAuthenticator::InvalidCredential => 'admin.sign.invalid',
				CmsAuthenticator::Throttled => 'admin.sign.throttled',
				default => throw $e,
			});

			return;
		}

		$this->restoreRequest($this->backlink);
		$this->redirect(':Admin:Dashboard:default');
	}
}
