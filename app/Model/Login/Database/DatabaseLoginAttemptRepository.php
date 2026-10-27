<?php

declare(strict_types=1);

namespace App\Model\Login\Database;

use App\Model\Login\LoginAttemptRepository;
use DateTimeImmutable;
use DateTimeZone;
use Nette\Database\Explorer;

final class DatabaseLoginAttemptRepository implements LoginAttemptRepository
{
	public function __construct(private readonly Explorer $explorer)
	{
	}


	public function recordFailure(string $email, string $ip, DateTimeImmutable $at): int
	{
		$this->explorer->query('INSERT INTO login_attempt', [
			'email' => $email,
			'ip' => $ip,
			'attempted_at' => self::utc($at),
		]);

		return (int) $this->explorer->getInsertId();
	}


	public function countForPair(string $email, string $ip, DateTimeImmutable $since): int
	{
		return self::toInt($this->explorer->query('SELECT COUNT(*) FROM login_attempt WHERE email = ? AND ip = ? AND attempted_at >= ?', $email, $ip, self::utc($since))->fetchField());
	}


	public function countForIp(string $ip, DateTimeImmutable $since): int
	{
		return self::toInt($this->explorer->query('SELECT COUNT(*) FROM login_attempt WHERE ip = ? AND attempted_at >= ?', $ip, self::utc($since))->fetchField());
	}


	public function removeFailure(int $id): void
	{
		$this->explorer->query('DELETE FROM login_attempt WHERE id = ?', $id);
	}


	public function clearPair(string $email, string $ip): void
	{
		$this->explorer->query('DELETE FROM login_attempt WHERE email = ? AND ip = ?', $email, $ip);
	}


	public function deleteOlderThan(DateTimeImmutable $before): int
	{
		return $this->explorer->query('DELETE FROM login_attempt WHERE attempted_at < ?', self::utc($before))->getRowCount() ?? 0;
	}


	private static function toInt(mixed $count): int
	{
		assert(is_int($count) || is_string($count));

		return (int) $count;
	}


	private static function utc(DateTimeImmutable $time): string
	{
		return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
	}
}
