<?php

declare(strict_types=1);

namespace App\Model\Login;

use DateTimeImmutable;

interface LoginAttemptRepository
{
	/** Returns the id of the new row. */
	public function recordFailure(string $email, string $ip, DateTimeImmutable $at): int;


	/** Counts failures with attempted_at >= $since. */
	public function countForPair(string $email, string $ip, DateTimeImmutable $since): int;


	/** Counts failures with attempted_at >= $since. */
	public function countForIp(string $ip, DateTimeImmutable $since): int;


	public function removeFailure(int $id): void;


	public function clearPair(string $email, string $ip): void;


	/** Deletes rows with attempted_at < $before; returns how many. */
	public function deleteOlderThan(DateTimeImmutable $before): int;
}
