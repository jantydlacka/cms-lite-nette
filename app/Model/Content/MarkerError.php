<?php

declare(strict_types=1);

namespace App\Model\Content;

enum MarkerError
{
	/** The line starts with `:::` but does not have the form of a marker. */
	case Syntax;

	/** A marker inside a list, a quote or an HTML block; markers stand only at the top level of the document. */
	case Misplaced;
}
