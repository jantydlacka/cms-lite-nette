<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Model\Login\LoginThrottle;
use App\Model\User\Email;
use App\Model\User\PasswordHasher;
use App\Model\User\User;
use App\Model\User\UserRepository;
use Nette\Http\IRequest;
use Nette\Security\AuthenticationException;
use Nette\Security\Authenticator;
use Nette\Security\IdentityHandler;
use Nette\Security\IIdentity;
use Nette\Security\SimpleIdentity;
use SensitiveParameter;

final class CmsAuthenticator implements Authenticator, IdentityHandler
{
	/** Distinct from the codes inherited from Authenticator (1 to 4). */
	public const int Throttled = 5;

	private const int MaxPasswordBytes = 72;


	public function __construct(
		private readonly UserRepository $users,
		private readonly PasswordHasher $hasher,
		private readonly LoginThrottle $throttle,
		private readonly IRequest $request,
		#[SensitiveParameter]
		private readonly string $dummyHash,
	) {
	}


	/** Verified for unknown accounts so their response time matches real ones; compiled with the PHP default cost (see CmsExtension). */
	public function dummyHash(): string
	{
		return $this->dummyHash;
	}


	public function authenticate(string $user, #[SensitiveParameter] string $password): IIdentity
	{
		$email = Email::normalize($user);
		$ip = $this->request->getRemoteAddress() ?? 'unknown';

		// Recorded before the password check, so parallel requests cannot slip past the limit.
		if (!$this->throttle->attempt($email, $ip)) {
			throw new AuthenticationException('Too many sign-in attempts.', self::Throttled);
		}

		$account = $this->users->findByEmail($email);
		$hash = $account === null ? null : $this->users->passwordHash($account->id);
		$valid = $this->hasher->verify($password, $hash ?? $this->dummyHash);

		// bcrypt ignores everything past byte 72, so a longer password must not match its prefix.
		if ($account === null || $hash === null || !$account->active || !$valid || strlen($password) > self::MaxPasswordBytes) {
			throw new AuthenticationException('Invalid credentials.', self::InvalidCredential);
		}

		$this->throttle->registerSuccess($email, $ip);
		if ($this->hasher->needsRehash($hash)) {
			$this->users->updatePasswordHash($account->id, $this->hasher->hash($password));
		}

		return self::identity($account);
	}


	public function sleepIdentity(IIdentity $identity): IIdentity
	{
		return new SimpleIdentity($identity->getId());
	}


	public function wakeupIdentity(IIdentity $identity): ?IIdentity
	{
		$id = filter_var($identity->getId(), FILTER_VALIDATE_INT);
		$account = $id === false ? null : $this->users->findById($id);

		return $account !== null && $account->active ? self::identity($account) : null;
	}


	private static function identity(User $account): SimpleIdentity
	{
		return new SimpleIdentity($account->id, [$account->role->value], ['name' => $account->name, 'email' => $account->email]);
	}
}
