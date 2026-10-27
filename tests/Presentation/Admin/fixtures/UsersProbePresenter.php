<?php

declare(strict_types=1);

namespace Tests\Presentation\Admin\fixtures;

use App\Core\Security\Resource;
use App\Presentation\Admin\AdminPresenter;
use Nette\Application\Responses\TextResponse;

/** Test-only administration page for the administrator alone. */
final class UsersProbePresenter extends AdminPresenter
{
	protected const Resource RESOURCE = Resource::Users;


	public function renderDefault(): void
	{
		$this->sendResponse(new TextResponse('users probe'));
	}
}
