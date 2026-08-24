<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Rules\Functions;

use DressCode\Analyses\PhpSymbols;
use DressCode\{Decision, DecisionKind, Domain, GlobalCalls, NodeRule, RuleContext, RuleInfo, Stage, Values};
use DressCode\Domains\Flag;
use DressCode\Rules\{Compiler, NodeHelpers};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Builder, Node, ParseException, SymbolKind, Token};
use PhpSyntax\Nodes\{ArgumentNode, ArrayItemNode, NameNode};
use PhpSyntax\Nodes\Expression\{ArrayNode, ClassConstantFetchNode, FunctionCallNode, MethodCallNode, StaticMethodCallNode, VariableNode};
use PhpSyntax\Nodes\Scalar\StringNode;
use PhpSyntax\Nodes\Statement\DeclareNode;
use function count, in_array;


/**
 * A callable is called directly, not through `call_user_func()`: `$callback($a)`, `($this->handler)($a)`,
 * `strtoupper($a)`, and `call_user_func_array($callback, $args)` is `$callback(...$args)` from PHP 8.1 on, where
 * unpacking takes the string keys the function passes as named arguments. A callable written as an array or as
 * a string naming a method is left alone, whether `[$x, 'run']` holds an object or a class not being in the code,
 * unless `hygiene.call_user_funcMethods` asks for the methods the code does name: `[$this, 'run']` is
 * `$this->run()`, `[self::class, 'make']` and `'Acme\Shop\Order::make'` are `self::make()` and `Order::make()`, the
 * fix through `self::` and `parent::` risky, as such a call forwards the class `static` means in the method.
 *
 * The direct call passes a variable by reference where the function declares the parameter so, which
 * `call_user_func()` never does and `call_user_func_array()` only for an element holding a reference already, but
 * where they do not, they warn at every call, so no working code changes.
 * What makes a fix risky is that `call_user_func()`, being internal, hands the arguments over as a caller without
 * strict types, so in a file declaring them the direct call may throw a TypeError where the old one converted; unless
 * PHP compiles the call into a direct one itself, which it does where it knows the name is the global function and
 * no argument is named or unpacked.
 */
#[RuleInfo(
	Stage::Structure,
	requires: ['php' => '>=7.0'],
	analyses: [PhpSymbols::class, NameResolver::class],
)]
final class NoCallUserFuncRule extends NodeRule
{
	private const Methods = 'hygiene.call_user_funcMethods';

	private bool $methods = false;


	public static function getDecisions(): array
	{
		return [
			new Decision(
				'hygiene.call_user_func',
				Domain::state('forbidden'),
				'`call_user_func($f, $x)` is `$f($x)`, `call_user_func_array($f, $a)` is `$f(...$a)`',
			),
			new Decision(
				self::Methods,
				new Flag,
				'Whether a method the callable names as `[$this, \'run\']`, `[self::class, \'make\']` or `\'Acme\Shop\Order::make\'` is called directly too',
				kind: DecisionKind::Parameter,
				default: false,
			),
		];
	}


	public function configure(Values $values): void
	{
		$this->methods = $values->get(self::Methods)->getFlag();
	}


	public function getVisitedNodes(): array
	{
		return [FunctionCallNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (
			!$node instanceof FunctionCallNode
			|| !$node->name instanceof NameNode
			|| ($function = GlobalCalls::findFunction($node, ['call_user_func' => true, 'call_user_func_array' => true], $context)) === null
			|| $node->arguments->isPartialApplication()
		) {
			return;
		}

		$args = $node->arguments->items->getItems();
		$callable = $args[0] ?? null;
		if (!$callable instanceof ArgumentNode || $callable->name !== null || $callable->ellipsis !== null) {
			return;
		}

		$named = match (true) {
			$callable->value instanceof ArrayNode => $this->methods ? self::buildMethodCall($callable->value) : null,
			$callable->value instanceof StringNode => self::buildNamedCall($callable->value, $context)
				?? ($this->methods ? self::buildStaticCall($callable->value) : null),
			default => null,
		};
		if (($callable->value instanceof ArrayNode || $callable->value instanceof StringNode) && $named === null) {
			return;
		}

		if ($function === 'call_user_func_array') {
			$array = $args[1] ?? null;
			if (
				count($args) !== 2
				|| !$array instanceof ArgumentNode
				|| $array->name !== null
				|| $array->ellipsis !== null
				|| version_compare($context->phpVersion, '8.1', '<')
			) {
				return;
			}

			$passed = [$array->value];
			$end = $node->arguments->closeParen;

		} else {
			$passed = array_map(
				fn(ArgumentNode $arg) => $arg->value,
				array_filter(array_slice($args, 1), fn($arg) => $arg instanceof ArgumentNode),
			);
			$end = ($args[1] ?? null)?->getFirstToken() ?? $node->arguments->closeParen;
		}

		// a comment between the name and the arguments the call keeps would be lost with them
		$fixable = !$node->name->getLastToken()->hasCommentUpTo($end);
		$uncertainty = GlobalCalls::findUncertainty($node, $context);
		$because = match (true) {
			// `self::make()` forwards the class the method was called for, which the callable of `self::class` names no more
			$named instanceof StaticMethodCallNode
				&& $named->class instanceof NameNode
				&& in_array(strtolower($named->class->text), ['self', 'parent'], true)
				=> '`static` in the method then means the class the caller was called for',
			$passed !== [] && self::declaresStrictTypes($context) && !self::isCompiledDirectly($node, $function, $context)
				=> 'under `strict_types` the direct call refuses an argument `' . $function . '()` converted',
			default => null,
		};

		if (!$context->report(
			$node->name,
			"The callable must be called directly, not through `$function()`"
			. ($fixable ? '.' : ', but a comment stands among its arguments.'),
			fixable: $fixable,
			risk: NodeHelpers::findRisk($because, $uncertainty),
			because: $because ?? $uncertainty,
		)) {
			return;
		}

		$call = $named ?? (new Builder)->call($callable->value);
		if ($function === 'call_user_func_array') {
			$builder = new Builder;
			$call->arguments = $builder->arguments([$builder->fragment(ArgumentNode::class, '...$arguments', arguments: $passed[0])]);
		} else {
			// what follows the call stays with the node it replaces, so the arguments must not bring it a second time
			$arguments = $node->arguments->withoutEdgeTrivia();
			$arguments->items->removeItem($arguments->items->getItems()[0]);
			$call->arguments = $arguments;
		}

		$node->replaceWith($call);
	}


	/**
	 * The call of the function a string callable names, without arguments, its name written bare only where nothing
	 * but the global function answers to it; null for a string naming a method, a keyword, or anything but a name.
	 */
	private static function buildNamedCall(StringNode $callable, RuleContext $context): ?FunctionCallNode
	{
		$name = self::findFunctionName($callable);
		if ($name === null) {
			return null;
		}

		$resolver = $context->getAnalysis(NameResolver::class);
		$bare = $resolver->getNamespace($callable) === ''
			&& !str_contains($name, '\\')
			&& !isset($resolver->getImports(SymbolKind::Function, $callable)[strtolower($name)]);
		try {
			$call = (new Builder)->expression(($bare ? '' : '\\') . $name . '()');
		} catch (ParseException) {
			return null;
		}

		return $call instanceof FunctionCallNode && $call->name instanceof NameNode && !$call->name->isKeyword()
			? $call
			: null;
	}


	/**
	 * The call of the method an array callable names, without arguments, where the code says whether it holds an object
	 * or a class: `[$this, 'run']` and `[self::class, 'make']`, `static::class`, `parent::class` and `Foo::class` alike.
	 */
	private static function buildMethodCall(ArrayNode $callable): MethodCallNode|StaticMethodCallNode|null
	{
		$items = $callable->items->getItems();
		[$target, $method] = count($items) === 2 ? $items : [null, null];
		if (
			!$target instanceof ArrayItemNode
			|| !$method instanceof ArrayItemNode
			|| array_any(
				[$target, $method],
				fn(ArrayItemNode $item) => $item->key !== null || $item->ampersand !== null || $item->ellipsis !== null,
			)
			|| !$method->value instanceof StringNode
			|| !preg_match('~^[a-z_\x80-\xff][\w\x80-\xff]*$~iD', $name = $method->value->toValue())
			|| $callable->hasInnerComment()
		) {
			return null;
		}

		$object = $target->value;
		return match (true) {
			$object instanceof VariableNode && $object->isThis() => (new Builder)->methodCall($object, $name),
			$object instanceof ClassConstantFetchNode
				&& $object->class instanceof NameNode
				&& strcasecmp((string) $object->plainName, 'class') === 0
				=> (new Builder)->staticMethodCall($object->class, $name),
			default => null,
		};
	}


	/**
	 * The call of the static method a string callable names as `'Acme\Shop\Order::make'`, the class fully qualified;
	 * null for any other string, `'self::make'` and its kin among them, which PHP deprecates.
	 */
	private static function buildStaticCall(StringNode $callable): ?StaticMethodCallNode
	{
		$identifier = '[a-z_\x80-\xff][\w\x80-\xff]*';
		return preg_match("~^\\\\?($identifier(?:\\\\$identifier)*)::($identifier)$~iD", $callable->toValue(), $m)
			&& !in_array(strtolower($m[1]), ['self', 'static', 'parent'], true)
			? (new Builder)->staticMethodCall('\\' . $m[1], $m[2])
			: null;
	}


	/** Whether PHP compiles the call into a direct call of the callable, which checks the types as the file does. */
	private static function isCompiledDirectly(FunctionCallNode $call, string $function, RuleContext $context): bool
	{
		return $call->name instanceof NameNode
			&& Compiler::isNameKnown($call->name, SymbolKind::Function, $context)
			&& Compiler::isOptimizedCall($call, $function, $context);
	}


	/** The function a string callable names, without a leading backslash; null for a method or anything but a name. */
	private static function findFunctionName(StringNode $string): ?string
	{
		return preg_match('~^\\\\?([a-z_\x80-\xff][\w\x80-\xff]*(?:\\\\[a-z_\x80-\xff][\w\x80-\xff]*)*)$~iD', $string->toValue(), $m)
			? $m[1]
			: null;
	}


	private static function declaresStrictTypes(RuleContext $context): bool
	{
		return array_any(
			$context->file->statements->getItems(),
			fn($statement) => $statement instanceof DeclareNode && $statement->findDirective('strict_types')?->value->text === '1',
		);
	}
}
