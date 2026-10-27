<?php

declare(strict_types=1);

namespace App\Model\Page;

interface PageTreeLock
{
	/** Takes the single page-tree lock until the surrounding transaction ends. Must run inside a transaction. */
	public function acquire(): void;
}
