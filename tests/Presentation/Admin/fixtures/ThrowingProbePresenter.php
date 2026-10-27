<?php

declare(strict_types=1);

namespace Tests\Presentation\Admin\fixtures;

use Nette\Application\UI\Presenter;
use RuntimeException;

/** Test-only page under /admin that fails, for the server error page. */
final class ThrowingProbePresenter extends Presenter
{
	public static ?RuntimeException $thrown = null;


	public function actionDefault(): void
	{
		throw self::$thrown = new RuntimeException('admin probe failure');
	}
}
