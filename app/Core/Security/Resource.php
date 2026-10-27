<?php

declare(strict_types=1);

namespace App\Core\Security;

enum Resource: string
{
	case Dashboard = 'dashboard';
	case Pages = 'pages';
	case News = 'news';
	case Users = 'users';
	case Settings = 'settings';
}
