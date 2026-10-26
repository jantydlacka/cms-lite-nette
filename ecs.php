<?php

declare(strict_types=1);

use PHP_CodeSniffer\Standards\Generic\Sniffs\WhiteSpace\DisallowSpaceIndentSniff;
use SlevomatCodingStandard\Sniffs\Namespaces\ReferenceUsedNamesOnlySniff;
use SlevomatCodingStandard\Sniffs\Namespaces\UnusedUsesSniff;
use SlevomatCodingStandard\Sniffs\TypeHints\DeclareStrictTypesSniff;
use SlevomatCodingStandard\Sniffs\TypeHints\ParameterTypeHintSniff;
use SlevomatCodingStandard\Sniffs\TypeHints\PropertyTypeHintSniff;
use SlevomatCodingStandard\Sniffs\TypeHints\ReturnTypeHintSniff;
use SlevomatCodingStandard\Sniffs\Variables\UnusedVariableSniff;
use SlevomatCodingStandard\Sniffs\Variables\UselessVariableSniff;
use Symplify\EasyCodingStandard\Config\ECSConfig;

return ECSConfig::configure()
	->withPaths([__DIR__ . '/app', __DIR__ . '/tests', __DIR__ . '/config', __DIR__ . '/www'])
	->withRootFiles()
	->withFileExtensions(['php', 'phpt'])
	->withSpacing(indentation: 'tab')
	->withPreparedSets(psr12: true)
	->withRules([
		DisallowSpaceIndentSniff::class,
	])
	->withConfiguredRule(DeclareStrictTypesSniff::class, [
		'declareOnFirstLine' => false,
		'linesCountBeforeDeclare' => 1,
		'spacesCountAroundEqualsSign' => 0,
	])
	->withRules([
		ParameterTypeHintSniff::class,
		PropertyTypeHintSniff::class,
		ReturnTypeHintSniff::class,
		UnusedUsesSniff::class,
		UnusedVariableSniff::class,
		UselessVariableSniff::class,
	])
	->withConfiguredRule(ReferenceUsedNamesOnlySniff::class, [
		'allowFallbackGlobalFunctions' => true,
		'allowFallbackGlobalConstants' => true,
		'searchAnnotations' => true,
	]);
