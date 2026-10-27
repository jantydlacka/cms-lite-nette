<?php

declare(strict_types=1);

namespace App\Model\Login;

use App\Model\User\Email;
use Psr\Clock\ClockInterface;

final class LoginThrottle
{
	public function __construct(
		private readonly LoginAttemptRepository $attempts,
		private readonly ClockInterface $clock,
		private readonly LoginLimits $limits,
	) {
	}


	/**
	 * Records the attempt as a failure, then counts including that row.
	 * Returns false (and removes the row, so blocked attempts do not extend the window) when over a limit.
	 */
	public function attempt(string $email, string $ip): bool
	{
		$email = self::emailKey($email);
		$ip = self::ipKey($ip);
		$now = $this->clock->now();
		$since = $now->modify('-' . $this->limits->windowMinutes . ' minutes');

		$this->attempts->deleteOlderThan($since);
		$id = $this->attempts->recordFailure($email, $ip, $now);

		if ($this->attempts->countForPair($email, $ip, $since) > $this->limits->maxPairFailures
			|| $this->attempts->countForIp($ip, $since) > $this->limits->maxIpFailures
		) {
			$this->attempts->removeFailure($id);

			return false;
		}

		return true;
	}


	public function registerSuccess(string $email, string $ip): void
	{
		$this->attempts->clearPair(self::emailKey($email), self::ipKey($ip));
	}


	/** Capped to the column length; longer inputs share one bucket (no such account can exist). */
	private static function emailKey(string $email): string
	{
		return mb_substr(Email::normalize($email), 0, 254);
	}


	/** IPv4 as is, IPv4-mapped IPv6 as IPv4, other IPv6 as its /64 prefix (zone id dropped); anything unparsable is "unknown". */
	private static function ipKey(string $ip): string
	{
		$packed = @inet_pton(explode('%', $ip, 2)[0]);
		if ($packed === false) {
			return 'unknown';
		}
		if (strlen($packed) === 4) {
			return (string) inet_ntop($packed);
		}
		if (str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
			return (string) inet_ntop(substr($packed, 12));
		}

		return (string) inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';
	}
}
