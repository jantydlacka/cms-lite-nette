<?php

declare(strict_types=1);

namespace App\Core\Database;

use App\Model\Transaction;
use Nette\Database\Explorer;

final class ExplorerTransaction implements Transaction
{
	public function __construct(private readonly Explorer $explorer)
	{
	}


	public function run(callable $work): mixed
	{
		return $this->explorer->transaction(static fn (): mixed => $work());
	}
}
