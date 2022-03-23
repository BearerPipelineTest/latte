<?php

/**
 * This file is part of the Latte (https://latte.nette.org)
 * Copyright (c) 2008 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Latte\Compiler;

use Latte\Context;
use Latte\Strict;


/**
 * Template code generator.
 */
final class TemplateGenerator
{
	use Strict;

	/** @var array<string, ?array{body: string, arguments: string, returns: string, comment: ?string}> */
	private array $methods = ['main' => null, 'prepare' => null];

	/** @var array<string, mixed> */
	private array $properties = [];

	/** @var array<string, mixed> */
	private array $constants = [];


	/**
	 * Compiles nodes to PHP file
	 */
	public function generate(
		PrintContext $context,
		Node $node,
		string $className,
		?string $comment = null,
		bool $strictMode = false,
	): string {
		$code = $node->print($context);
		$extractParams = self::buildParams($context->paramsExtraction, '$this->params', $context);
		$this->addMethod('main', $extractParams . $code . ' return get_defined_vars();', '', 'array');

		if ($context->initialization) {
			$this->addMethod('prepare', $extractParams . $context->initialization, '', 'void');
		}

		$contentType = $context->getContentType();
		if ($contentType !== Context::Html) {
			$this->addConstant('ContentType', $contentType);
		}

		$this->generateBlocks($context->blocks, $context);

		$members = [];
		foreach ($this->constants as $name => $value) {
			$members[] = "\tprotected const $name = " . PhpHelpers::dump($value, true) . ';';
		}

		foreach ($this->properties as $name => $value) {
			$members[] = "\tpublic $$name = " . PhpHelpers::dump($value, true) . ';';
		}

		foreach (array_filter($this->methods) as $name => $method) {
			$members[] = ($method['comment'] === null ? '' : "\n\t/** " . str_replace('*/', '* /', $method['comment']) . ' */')
				. "\n\tpublic function $name($method[arguments])"
				. ($method['returns'] ? ': ' . $method['returns'] : '')
				. "\n\t{\n"
				. ($method['body'] ? "\t\t$method[body]\n" : '') . "\t}";
		}

		$code = "<?php\n\n"
			. ($strictMode ? "declare(strict_types=1);\n\n" : '')
			. "use Latte\\Runtime as LR;\n\n"
			. ($comment === null ? '' : '/** ' . str_replace('*/', '* /', $comment) . " */\n")
			. "final class $className extends Latte\\Runtime\\Template\n{\n"
			. implode("\n\n", $members)
			. "\n\n}\n";

		$code = PhpHelpers::optimizeEcho($code);
		$code = PhpHelpers::reformatCode($code);
		return $code;
	}


	/** @param  Block[]  $blocks */
	private function generateBlocks(array $blocks, PrintContext $context): void
	{
		foreach ($blocks as $block) {
			if (!$block->isDynamic()) {
				$meta[$block->layer][$block->name->value] = $context->getContentType() === $block->context
					? $block->method
					: [$block->method, $block->context];
			}

			$body = $block->content;
			if (str_contains($body, '$')) {
				$embedded = $block->tag->name === 'block' && is_int($block->layer) && $block->layer;
				$body = ($block->isDynamic() ? '' : 'extract(' . ($embedded ? 'end($this->varStack)' : '$this->params') . ');')
					. $this->buildParams($block->parameters, '$ʟ_args', $context)
					. 'unset($ʟ_args);'
					. "\n\n" . $body;
			}

			$this->addMethod(
				$block->method,
				$body,
				'array $ʟ_args',
				'void',
				$block->tag->getNotation(true) . ' on line ' . $block->tag->line,
			);
		}

		if (isset($meta)) {
			$this->addConstant('Blocks', $meta);
		}
	}


	private function buildParams(array $params, string $cont, PrintContext $context): string
	{
		foreach ($params as $i => &$param) {
			$param = $context->format(
				'%raw = %raw[%dump] ?? %raw[%dump] ?? %raw;',
				$param->var,
				$cont,
				$i,
				$cont,
				$param->var->name,
				$param->expr,
			);
		}

		return $params ? implode('', $params) : "extract($cont);";
	}


	/**
	 * Adds custom method to template.
	 * @internal
	 */
	public function addMethod(
		string $name,
		string $body,
		string $arguments = '',
		string $returns = '',
		?string $comment = null,
	): void {
		$body = trim($body);
		$this->methods[$name] = compact('body', 'arguments', 'returns', 'comment');
	}


	/**
	 * Returns custom methods.
	 * @return array<string, ?array{body: string, arguments: string, returns: string, comment: ?string}>
	 * @internal
	 */
	public function getMethods(): array
	{
		return $this->methods;
	}


	/**
	 * Adds custom property to template.
	 * @internal
	 */
	public function addProperty(string $name, mixed $value): void
	{
		$this->properties[$name] = $value;
	}


	/**
	 * Returns custom properites.
	 * @return array<string, mixed>
	 * @internal
	 */
	public function getProperties(): array
	{
		return $this->properties;
	}


	/**
	 * Adds custom constant to template.
	 * @internal
	 */
	public function addConstant(string $name, mixed $value): void
	{
		$this->constants[$name] = $value;
	}
}
