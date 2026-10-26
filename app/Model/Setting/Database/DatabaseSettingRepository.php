<?php

declare(strict_types=1);

namespace App\Model\Setting\Database;

use App\Model\Setting\SettingRepository;
use Nette\Database\Explorer;

final class DatabaseSettingRepository implements SettingRepository
{
	public function __construct(
		private readonly Explorer $explorer,
	) {
	}


	public function get(string $key, string $locale = ''): ?string
	{
		$value = $this->explorer->query(
			'SELECT `value` FROM setting WHERE `key` = ? AND locale = ?',
			$key,
			$locale,
		)->fetchField();

		return is_string($value) ? $value : null;
	}


	public function set(string $key, string $value, string $locale = ''): void
	{
		$this->explorer->query(
			'INSERT INTO setting (`key`, locale, `value`) VALUES (?, ?, ?)
			ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)',
			$key,
			$locale,
			$value,
		);
	}
}
