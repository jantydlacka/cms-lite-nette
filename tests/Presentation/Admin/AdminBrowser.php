<?php

declare(strict_types=1);

namespace Tests\Presentation\Admin;

use App\Model\User\CreateUser;
use App\Model\User\Role;
use App\Model\User\UserService;
use LogicException;
use Nette\Database\Explorer;
use Nette\DI\Container;
use SimpleXMLElement;
use Tests\Support\HttpApplication;
use Tests\Support\HttpResult;

/** Seeds accounts and drives the sign-in and sign-out forms the way a browser would. */
final class AdminBrowser
{
	public const string Password = 'correct-password-1';


	public static function seed(Container $container, string $email, string $name, Role $role): int
	{
		return $container->getByType(UserService::class)->create(new CreateUser($email, $name, self::Password, $role))->id;
	}


	/** @param array<string, mixed> $values */
	public static function updateUser(Container $container, int $id, array $values): void
	{
		$container->getByType(Explorer::class)->table('user')->where('id', $id)->update($values);
	}


	/**
	 * Loads the sign-in page, then posts its form with the hidden fields it rendered.
	 *
	 * @param array<string, ?string> $headers for the POST only
	 */
	public static function signIn(HttpApplication $app, string $email, string $password = self::Password, string $page = '/admin/sign-in', array $headers = []): HttpResult
	{
		$form = self::form($app->get($page), '//form[@data-testid="sign-in-form"]');
		$body = self::hiddenFields($form) + ['email' => $email, 'password' => $password, 'send' => ''];

		return $app->post((string) $form['action'], $body, $headers);
	}


	/**
	 * Posts the sign-out form of the dashboard.
	 *
	 * @param array<string, ?string> $headers
	 */
	public static function signOut(HttpApplication $app, array $headers = []): HttpResult
	{
		return $app->post(self::signOutAction($app), [], $headers);
	}


	public static function signOutAction(HttpApplication $app): string
	{
		$form = self::form($app->get('/admin'), '//form[.//*[@data-testid="sign-out"]]');
		self::assertPost($form);

		return (string) $form['action'];
	}


	private static function form(HttpResult $result, string $xpath): SimpleXMLElement
	{
		$found = $result->dom()->xpath($xpath);
		if ($found === null || $found === false || $found === []) {
			throw new LogicException("No form '$xpath' on the page (HTTP $result->code).");
		}

		return $found[0];
	}


	private static function assertPost(SimpleXMLElement $form): void
	{
		if (strtolower((string) $form['method']) !== 'post') {
			throw new LogicException('The form does not use POST.');
		}
	}


	/** @return array<string, string> */
	private static function hiddenFields(SimpleXMLElement $form): array
	{
		$fields = [];
		$inputs = $form->xpath('.//input[@type="hidden"]');
		foreach (is_array($inputs) ? $inputs : [] as $input) {
			$fields[(string) $input['name']] = (string) $input['value'];
		}

		return $fields;
	}
}
