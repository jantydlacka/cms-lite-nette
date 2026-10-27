<?php

declare(strict_types=1);

namespace Tests\Support\fixtures;

use Nette\Application\UI\Presenter;
use Nette\Database\Connection;
use Nette\Http\IRequest;
use Nette\Http\IResponse;
use Nette\Http\Session;

/** Echoes what the HTTP test client sent; touches the session only when asked. */
final class ProbePresenter extends Presenter
{
	public function __construct(
		private readonly Session $session,
		private readonly IRequest $httpRequest,
		private readonly IResponse $httpResponse,
		private readonly Connection $connection,
	) {
		parent::__construct();
	}


	public function actionDefault(): void
	{
		if ($this->httpRequest->getQuery('dbsession') !== null) {
			$this->sendJson((array) $this->connection->fetch('SELECT @@session.foreign_key_checks = @@global.foreign_key_checks AS fk, @@session.sql_mode = @@global.sql_mode AS mode, @@session.time_zone = @@global.time_zone AS tz'));
		}

		$value = null;
		if ($this->httpRequest->getQuery('nosession') === null) {
			$section = $this->session->getSection('probe');
			$write = $this->httpRequest->getQuery('write');
			if (is_string($write)) {
				$section->set('value', $write);
			}

			$value = $section->get('value');
		}

		$cookie = $this->httpRequest->getQuery('cookie');
		if ($cookie === 'set') {
			$this->httpResponse->setCookie('probe', 'v1', null);
		} elseif ($cookie === 'delete') {
			$this->httpResponse->deleteCookie('probe');
		}

		$this->sendJson([
			'session' => $value,
			'cookies' => $this->httpRequest->getCookies(),
			'post' => $this->httpRequest->getPost(),
			'method' => $this->httpRequest->getMethod(),
			'secFetchSite' => $this->httpRequest->getHeader('Sec-Fetch-Site'),
		]);
	}
}
