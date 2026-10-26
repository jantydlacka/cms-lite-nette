<?php

declare(strict_types=1);

namespace App\Model\Redirect\Database;

use App\Model\Redirect\PathRedirectRepository;
use App\Model\Redirect\RedirectTarget;
use DateTimeZone;
use Nette\Database\Explorer;
use Psr\Clock\ClockInterface;

final class DatabasePathRedirectRepository implements PathRedirectRepository
{
	public function __construct(
		private readonly Explorer $explorer,
		private readonly ClockInterface $clock,
	) {
	}


	public function findTarget(string $locale, string $path): ?RedirectTarget
	{
		$row = $this->explorer->table('path_redirect')
			->where('locale', $locale)
			->where('old_path', $path)
			->fetch();
		if ($row === null) {
			return null;
		}
		assert(is_string($row->target_type) && (is_int($row->target_id) || is_string($row->target_id)));

		return new RedirectTarget($row->target_type, (int) $row->target_id);
	}


	public function record(string $locale, string $oldPath, RedirectTarget $target): void
	{
		$now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
		$this->explorer->query('INSERT INTO path_redirect ? ON DUPLICATE KEY UPDATE target_type = VALUES(target_type), target_id = VALUES(target_id), created_at = VALUES(created_at)', [
			'locale' => $locale,
			'old_path' => $oldPath,
			'target_type' => $target->type,
			'target_id' => $target->id,
			'created_at' => $now,
		]);
	}


	public function deleteByPath(string $locale, string $path): void
	{
		$this->explorer->table('path_redirect')->where('locale', $locale)->where('old_path', $path)->delete();
	}


	public function deleteForTarget(RedirectTarget $target): void
	{
		$this->explorer->table('path_redirect')->where('target_type', $target->type)->where('target_id', $target->id)->delete();
	}
}
