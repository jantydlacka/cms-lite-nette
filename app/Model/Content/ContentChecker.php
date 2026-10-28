<?php

declare(strict_types=1);

namespace App\Model\Content;

final class ContentChecker
{
	public function __construct(
		private readonly ComponentScanner $scanner,
		private readonly ComponentRegistry $registry,
	) {
	}


	/** @return list<ContentProblem> ordered by line */
	public function check(string $markdown, ContentKind $kind): array
	{
		$problems = [];
		foreach ($this->scanner->scan($markdown) as $marker) {
			$problem = $this->problemOf($marker, $kind);
			if ($problem !== null) {
				$problems[] = $problem;
			}
		}

		usort($problems, static fn (ContentProblem $a, ContentProblem $b): int => $a->line <=> $b->line);

		return $problems;
	}


	private function problemOf(ComponentMarker $marker, ContentKind $kind): ?ContentProblem
	{
		$line = $marker->line;
		if ($marker->error !== null) {
			return new ContentProblem($line, match ($marker->error) {
				MarkerError::Syntax => 'content.component.syntax',
				MarkerError::Misplaced => 'content.component.misplaced',
			}, ['line' => $line]);
		}

		$type = (string) $marker->type;
		$component = $this->registry->get($type);
		if ($component === null) {
			return new ContentProblem($line, 'content.component.unknown', [
				'line' => $line,
				'type' => $type,
				'available' => implode(', ', $this->registry->names()),
			]);
		}

		if (!$component->allowedIn($kind)) {
			return new ContentProblem($line, 'content.component.notAllowedInNews', ['line' => $line, 'type' => $type]);
		}

		try {
			$component->arguments($marker->arguments);
		} catch (InvalidComponentArguments $e) {
			return new ContentProblem($line, $e->messageKey, ['line' => $line]);
		}

		return null;
	}
}
