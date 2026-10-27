<?php

declare(strict_types=1);

namespace Tests\Support\fixtures;

use Nette\Application\UI\Presenter;
use Nette\Http\Session;

/** Starts the session only while its template is being rendered, after output has begun. */
final class LateSessionPresenter extends Presenter
{
	public function __construct(
		private readonly Session $session,
	) {
		parent::__construct();
	}


	public function renderDefault(): void
	{
		$this->setLayout(false);
		$this->template->setFile(__DIR__ . '/late-session.latte');
		$this->template->session = $this->session;
	}
}
