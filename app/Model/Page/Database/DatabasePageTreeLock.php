<?php

declare(strict_types=1);

namespace App\Model\Page\Database;

use App\Model\Page\PageTreeLock;
use LogicException;
use Nette\Database\Explorer;
use UnexpectedValueException;

final class DatabasePageTreeLock implements PageTreeLock
{
	public function __construct(private readonly Explorer $explorer)
	{
	}


	public function acquire(): void
	{
		if (!$this->explorer->getConnection()->getPdo()->inTransaction()) {
			throw new LogicException('The page tree lock must be acquired inside a transaction.');
		}

		// A missing row would only gap-lock and serialize nothing, so it is an error.
		if ($this->explorer->query('SELECT id FROM page_tree_lock WHERE id = 1 FOR UPDATE')->fetch() === null) {
			throw new UnexpectedValueException('The page_tree_lock row is missing.');
		}
	}
}
