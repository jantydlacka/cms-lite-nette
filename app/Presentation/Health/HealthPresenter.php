<?php

declare(strict_types=1);

namespace App\Presentation\Health;

use Nette\Application\IPresenter;
use Nette\Application\Request;
use Nette\Application\Response;
use Nette\Application\Responses\TextResponse;
use Nette\Assets\Registry;
use Nette\Http\IResponse;

/**
 * Container health check: answers without the database, but fails while an asset of the layout
 * is missing (a missing asset breaks every page with status 200, see README Deployment note).
 */
final class HealthPresenter implements IPresenter
{
	/** Assets the public and the administration layout reference through {asset}; HealthTest keeps the list in sync. */
	public const LAYOUT_ASSETS = ['vendor/pico.min.css', 'front.css', 'front.js', 'admin.css'];


	public function __construct(
		private readonly Registry $assets,
		private readonly IResponse $httpResponse,
	) {
	}


	public function run(Request $request): Response
	{
		$missing = array_filter(self::LAYOUT_ASSETS, fn (string $asset): bool => $this->assets->tryGetAsset($asset) === null);
		$this->httpResponse->setContentType('text/plain', 'utf-8');
		if ($missing !== []) {
			$this->httpResponse->setCode(IResponse::S503_ServiceUnavailable);

			return new TextResponse('missing asset: ' . implode(', ', $missing));
		}

		return new TextResponse('ok');
	}
}
