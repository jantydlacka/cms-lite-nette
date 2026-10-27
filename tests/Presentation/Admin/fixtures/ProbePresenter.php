<?php

declare(strict_types=1);

namespace Tests\Presentation\Admin\fixtures;

use App\Presentation\Admin\AdminPresenter;
use Nette\Application\Responses\TextResponse;

/** Test-only administration page with a component signal. */
final class ProbePresenter extends AdminPresenter
{
	public function renderDefault(): void
	{
		$this->sendResponse(new TextResponse('probe'));
	}


	protected function createComponentProbe(): ProbeControl
	{
		return new ProbeControl();
	}
}
