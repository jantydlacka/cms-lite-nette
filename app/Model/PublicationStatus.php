<?php

declare(strict_types=1);

namespace App\Model;

enum PublicationStatus: string
{
	case Draft = 'draft';
	case Published = 'published';
}
