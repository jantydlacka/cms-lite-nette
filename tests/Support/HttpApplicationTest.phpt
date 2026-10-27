<?php

declare(strict_types=1);

use Nette\Http\Helpers;
use Nette\Http\Request;
use Nette\Http\UrlScript;
use Nette\Utils\Json;
use Tester\Assert;
use Tests\Support\HttpApplication;
use Tests\Support\HttpResult;
use Tests\Support\RecordingHttpResponse;

require __DIR__ . '/../bootstrap.php';

/** @return array{session: ?string, cookies: array<string, string>, post: array<string, mixed>, method: string, secFetchSite: ?string} */
function probe(HttpResult $result): array
{
	$data = Json::decode($result->body, forceArrays: true);
	assert(is_array($data));

	return $data; // @phpstan-ignore return.type
}


$app = HttpApplication::boot([__DIR__ . '/support-routes.neon']);

// Session value survives to the next request through the jar; a new browser starts clean.
$first = $app->get('/support-probe?write=hello');
Assert::same(200, $first->code);
Assert::true($first->sessionStarted());
Assert::notSame(null, $first->sessionId());
Assert::same('hello', probe($app->get('/support-probe'))['session']);
Assert::same($first->sessionId(), $app->get('/support-probe')->sessionId());

$app->newBrowser();
$fresh = $app->get('/support-probe');
Assert::null(probe($fresh)['session']);
Assert::null($fresh->sessionId());

// A page that never touches the session reports it did not run.
$app->newBrowser();
$plain = $app->get('/support-probe?nosession=1');
Assert::false($plain->sessionStarted());
Assert::null($plain->sessionId());

// Cookies set by the response are sent back; deleting removes them.
$app->newBrowser();
$app->get('/support-probe?cookie=set');
Assert::same('v1', probe($app->get('/support-probe?nosession=1'))['cookies']['probe'] ?? null);
$app->get('/support-probe?cookie=delete&nosession=1');
Assert::false(isset(probe($app->get('/support-probe?nosession=1'))['cookies']['probe']));

// POST body reaches getPost().
$posted = $app->post('/support-probe?nosession=1', ['a' => 'b', 'n' => ['x' => '1']]);
Assert::same(['a' => 'b', 'n' => ['x' => '1']], probe($posted)['post']);
Assert::same('POST', probe($posted)['method']);
Assert::same('GET', probe($app->get('/support-probe?nosession=1'))['method']);

// Sec-Fetch-Site: default, override, omit.
Assert::same('same-origin', probe($app->get('/support-probe?nosession=1'))['secFetchSite']);
Assert::same('cross-site', probe($app->get('/support-probe?nosession=1', ['Sec-Fetch-Site' => 'cross-site']))['secFetchSite']);
Assert::null(probe($app->get('/support-probe?nosession=1', ['Sec-Fetch-Site' => null]))['secFetchSite']);
Assert::same('cross-site', probe($app->post('/support-probe?nosession=1', [], ['Sec-Fetch-Site' => 'cross-site']))['secFetchSite']);

// Header names are case-insensitive: a lower-case key overrides or omits the default.
Assert::same('cross-site', probe($app->get('/support-probe?nosession=1', ['sec-fetch-site' => 'cross-site']))['secFetchSite']);
Assert::null(probe($app->get('/support-probe?nosession=1', ['sec-fetch-site' => null]))['secFetchSite']);

// The jar can be written and read by the test.
$app->newBrowser();
Assert::same([], $app->cookies());
$app->setCookie('_nss', '1');
Assert::same('1', probe($app->get('/support-probe?nosession=1'))['cookies']['_nss'] ?? null);
Assert::same(['_nss' => '1'], $app->cookies());
$app->newBrowser();
Assert::same([], $app->cookies());
$app->get('/support-probe?write=x');
Assert::true(isset($app->cookies()[session_name()]));
$app->newBrowser();
Assert::same([], $app->cookies());

// Nette\Http\Helpers::initCookie works against the recording response and keeps sameSite.
$response = new RecordingHttpResponse();
Helpers::initCookie(new Request(new UrlScript('http://localhost/', '/')), $response);
$cookie = $response->getCookies()[0];
Assert::same(Helpers::StrictCookieName, $cookie['name']);
Assert::same('Strict', $cookie['sameSite']);
Assert::false($cookie['partitioned']);

// A session started after output began cannot send its cookie in production: the client refuses such a request.
$app->newBrowser();
Assert::exception(fn () => $app->get('/support-late-session'), LogicException::class, '%a%session started after output began%a%');
