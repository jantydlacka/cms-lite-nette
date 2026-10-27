<?php

declare(strict_types=1);

namespace App\Model\Login;

final readonly class LoginLimits
{
	public function __construct(
		public int $maxPairFailures,
		public int $maxIpFailures,
		public int $windowMinutes,
	) {
	}
}
