<?php

declare(strict_types=1);

namespace App\Presentation\Components;

use App\Core\Localization\CurrentLocale;
use App\Core\Markdown\ComponentHtmlRenderer;
use App\Model\Content\ContentComponent;
use App\Model\Content\ContentContext;
use App\Model\Content\ContentProblem;
use LogicException;
use Nette\Application\Application;
use Nette\Application\UI\Presenter;
use Nette\Application\UI\TemplateFactory;
use Nette\Bridges\ApplicationLatte\Template;

/**
 * Renders `<name>.latte` of this directory as a template of the running presenter, so links and filters work as on a page.
 * When it renders nothing in the preview, `<name>.empty.latte` (if any) says so instead.
 */
final class LatteComponentRenderer implements ComponentHtmlRenderer
{
	public function __construct(
		private readonly Application $application,
		private readonly TemplateFactory $templateFactory,
		private readonly CurrentLocale $currentLocale,
	) {
	}


	/** @param array<string, mixed> $arguments */
	public function render(ContentComponent $component, array $arguments, ContentContext $context): string
	{
		$parameters = ['component' => $component, 'arguments' => $arguments, 'context' => $context];

		// Texts and dates of the component follow the content, also in the preview of a translation.
		$locale = $this->currentLocale->get();
		$this->currentLocale->set($context->locale);
		try {
			$html = trim($this->template()->renderToString(__DIR__ . '/' . $component->name() . '.latte', $parameters));
		} finally {
			$this->currentLocale->set($locale);
		}

		// A note for the editor is admin text, so it stays in the language of the admin.
		$empty = __DIR__ . '/' . $component->name() . '.empty.latte';
		if ($html === '' && $context->preview && is_file($empty)) {
			return trim($this->template()->renderToString($empty, $parameters));
		}

		return $html;
	}


	public function renderProblem(ContentProblem $problem): string
	{
		return trim($this->template()->renderToString(__DIR__ . '/@problem.latte', ['problem' => $problem]));
	}


	private function template(): Template
	{
		$presenter = $this->application->getPresenter();
		if (!$presenter instanceof Presenter) {
			throw new LogicException('Components are rendered only inside a presenter.');
		}

		$template = $this->templateFactory->createTemplate($presenter);
		assert($template instanceof Template);

		return $template;
	}
}
