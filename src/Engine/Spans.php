<?php declare(strict_types=1);

/**
 * This file is part of the DressCode, a coding style and upgrade tool for PHP (https://dresscode.run)
 * Copyright (c) 2026 David Grudl (https://davidgrudl.com)
 */

namespace DressCode\Engine;

use PhpSyntax\{Node, Token, Trivia};
use function strlen;


/**
 * The span of a report, its byte offset and length: the trivia it is about, or the line of it a report on one line
 * of a trivia names; the whitespace of the gap a gap report is about, empty where none stands there; or the node it
 * was reported at. Measured in the text as read, by the positions the lexer gave, or in the current text of the file.
 * @internal
 */
final readonly class Spans
{
	public function __construct(
		/** the text as read, which the positions of the lexer point into; null measures the current text */
		private ?string $code = null,
	) {
	}


	/**
	 * Null where the span starts or ends at a token or a trivia that has no place in the measured text: one a fix
	 * wrote, or whose text a fix changed.
	 * @return ?array{int, int}
	 */
	public function find(Node|Token $at, ?Trivia $trivia, int $lineInTrivia = 0, ?Token $gap = null, ?Token $follows = null): ?array
	{
		if ($trivia !== null) {
			$start = $this->findTriviaStart($at, $trivia);
			return $start === null ? null : self::narrowToLine($start, $trivia->text, $lineInTrivia);
		} elseif ($gap !== null && $follows === null) {
			$previous = $gap->getPrevious();
			$start = $previous === null ? null : $this->findEnd($previous);
			$end = $this->findStart($gap);
		} else {
			$first = $at->getFirstToken();
			$last = $at->getLastToken();
			$start = $first === null ? null : $this->findStart($first);
			$end = $last === null ? null : $this->findEnd($last);
		}

		return $start === null || $end === null || $end < $start ? null : [$start, $end - $start];
	}


	private function findStart(Token $token): ?int
	{
		return $this->code === null
			? $token->getCurrentOffset()
			: ($token->pos < 0 ? null : $token->pos);
	}


	private function findEnd(Token $token): ?int
	{
		$start = $this->findStart($token);
		return $start === null || ($this->code !== null && substr($this->code, $start, strlen($token->text)) !== $token->text)
			? null
			: $start + strlen($token->text);
	}


	/** The start of the trivia, counted in the current text from the token it stands at. */
	private function findTriviaStart(Node|Token $at, Trivia $trivia): ?int
	{
		if ($this->code !== null) {
			return $trivia->pos < 0 ? null : $trivia->pos;
		}

		$first = $at->getFirstToken();
		$last = $at->getLastToken();
		foreach ($first === $last ? [$first] : [$first, $last] as $token) {
			$offset = $token?->getCurrentOffset();
			if ($offset === null) {
				continue;
			}

			$leading = array_search($trivia, $token->leadingTrivia, strict: true);
			if ($leading !== false) {
				foreach (array_slice($token->leadingTrivia, $leading) as $item) {
					$offset -= strlen($item->text);
				}

				return $offset;
			}

			$offset += strlen($token->text);
			foreach ($token->trailingTrivia as $item) {
				if ($item === $trivia) {
					return $offset;
				}

				$offset += strlen($item->text);
			}
		}

		return null;
	}


	/**
	 * The whole trivia, or the line of it a report on a later line names, without its line ending.
	 * @return ?array{int, int}
	 */
	private static function narrowToLine(int $start, string $text, int $lineInTrivia): ?array
	{
		if ($lineInTrivia === 0) {
			return [$start, strlen($text)];
		}

		$lines = (array) preg_split('~\r\n|\r|\n~', $text, -1, PREG_SPLIT_OFFSET_CAPTURE);
		[$line, $offset] = $lines[$lineInTrivia] ?? [null, 0];
		return $line === null ? null : [$start + $offset, strlen($line)];
	}
}
