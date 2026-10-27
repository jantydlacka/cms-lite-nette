<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

use App\Core\Security\Resource;
use App\Model\User\Role;
use App\Presentation\BasePresenter;
use Nette\Application\Attributes\Requires;
use Nette\Application\Request;
use Nette\Http\FetchSite;
use Nette\Http\IResponse;
use Nette\Security\SimpleIdentity;
use Nette\Security\User;

/** Pages for signed-in users; decides by isLoggedIn(), as a kept identity outlives the sign-out. */
abstract class AdminPresenter extends BasePresenter
{
	protected const Resource RESOURCE = Resource::Dashboard;


	protected function startup(): void
	{
		parent::startup();
		// Before the sign-in check, so that `/admin/` redirects like a public path.
		$this->canonicalize();

		$user = $this->getUser();
		if (!$user->isLoggedIn()) {
			$expired = $user->getLogoutReason() === User::LogoutInactivity;
			// A vetoed identity stays authenticated in the session; reactivating the account must not revive it.
			$user->logout();
			$this->flashMessage($expired ? 'admin.sign.expired' : 'admin.sign.required');
			// Stored: a GET without a signal, or a same-origin POST (a form; restored without running a signal).
			// A GET is restored by a plain redirect, so a stored signal in it would run after sign-in.
			$request = $this->getHttpRequest();
			$store = $request->isMethod('POST')
				? $request->isFrom(FetchSite::SameOrigin)
				: $request->isMethod('GET') && $this->getSignal() === null;
			$this->redirect(':Admin:Sign:in', $store ? ['backlink' => $this->storeRequest()] : []);
		}

		if (!$user->isAllowed(static::RESOURCE->value)) {
			$this->error(httpCode: IResponse::S403_Forbidden);
		}
	}


	protected function beforeRender(): void
	{
		parent::beforeRender();
		$user = $this->getUser();
		$identity = $user->getIdentity();
		assert($identity instanceof SimpleIdentity);
		$role = $user->getRoles()[0] ?? null;
		assert(is_string($role));
		// SimpleIdentity inherits getData() from the deprecated Identity; the method itself is current.
		$this->template->adminUser = $identity->getData()['name']; // @phpstan-ignore method.deprecatedClass
		$this->template->adminRoleKey = 'admin.role.' . Role::from($role)->value;
		$this->template->demoData = $this->cmsConfig->demoData;
	}


	/**
	 * A restored POST (after sign-in) runs no signal: its method and origin checks would see the sign-in POST,
	 * not the stored request. The signal stays set (private in Presenter), so a form still loads its restored values.
	 */
	public function processSignal(): void
	{
		if ($this->getRequest()->hasFlag(Request::RESTORED)) {
			return;
		}

		parent::processSignal();
	}


	#[Requires(methods: 'POST')]
	public function handleSignOut(): void
	{
		$this->getUser()->logout();
		$this->flashMessage('admin.sign.outDone');
		$this->redirect(':Admin:Sign:in');
	}
}
