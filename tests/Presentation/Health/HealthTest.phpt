<?php

declare(strict_types=1);

use App\Presentation\Health\HealthPresenter;
use Nette\Database\Connection;
use Nette\Utils\FileSystem;
use Tester\Assert;
use Tests\Support\HttpApplication;

require __DIR__ . '/../../bootstrap.php';

test('/_health answers ok on an empty database without touching it', function (): void {
	$result = HttpApplication::boot()->get('/_health');

	Assert::same(200, $result->code);
	Assert::same('ok', $result->body);
	Assert::same('text/plain; charset=utf-8', $result->header('Content-Type'));
	$connections = $result->container->findByType(Connection::class);
	Assert::count(1, $connections);
	Assert::false($result->container->isCreated($connections[0]));
});


test('/_health is 503 and names the asset when one the layout needs is missing', function (): void {
	$thread = getenv('NETTE_TESTER_THREAD');
	$dir = __DIR__ . '/../../../temp/tests/health-assets-' . ($thread === false ? '0' : $thread);
	FileSystem::delete($dir);
	try {
		foreach (array_diff(HealthPresenter::LAYOUT_ASSETS, ['front.css']) as $asset) {
			FileSystem::copy(__DIR__ . '/../../../www/assets/' . $asset, "$dir/assets/$asset");
		}

		$result = HttpApplication::boot([['assets' => ['basePath' => $dir]]])->get('/_health');

		Assert::same(503, $result->code);
		Assert::same('missing asset: front.css', $result->body);
	} finally {
		FileSystem::delete($dir);
	}
});


test('the checked assets are exactly those the layouts reference', function (): void {
	$assets = [];
	foreach (['@layout.latte', 'Admin/@layout.latte'] as $layout) {
		preg_match_all("~\\{asset '([^']+)'~", FileSystem::read(__DIR__ . '/../../../app/Presentation/' . $layout), $matches);
		$assets = [...$assets, ...$matches[1]];
	}

	Assert::same(array_values(array_unique($assets)), HealthPresenter::LAYOUT_ASSETS);
});
