<?php

declare(strict_types=1);

namespace App\Model\Content;

enum ContentKind: string
{
	case Page = 'page';
	case News = 'news';
}
