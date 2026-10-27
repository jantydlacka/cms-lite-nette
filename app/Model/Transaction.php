<?php

declare(strict_types=1);

namespace App\Model;

interface Transaction
{
	/**
	 * Runs $work in a transaction: commits when it returns, rolls back and re-throws when it throws.
	 * A nested run() joins the outer transaction (no savepoint).
	 *
	 * Pitfall: only the outermost run() rolls back. If outer code catches an exception from an inner run()
	 * and carries on, the inner writes are committed together with the rest.
	 *
	 * @template T
	 * @param callable(): T $work
	 * @return T
	 */
	public function run(callable $work): mixed;


	public function inTransaction(): bool;
}
