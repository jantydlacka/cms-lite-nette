<?php

declare(strict_types=1);

namespace App\Model\User;

enum Role: string
{
	case Editor = 'editor';
	case Admin = 'admin';
}
