<?php

declare(strict_types=1);

namespace OCA\Epubviewer\Service;

use OCP\Files\Node;
use OCP\Files\NotFoundException;

/** Validation shared by authenticated and public DAV reader paths. */
final class ReaderPath {
	/** @return list<string> */
	public static function segments(string $path, bool $decode = true): array {
		// Permit one URL delimiter at either end, but reject repeated delimiters.
		if (str_starts_with($path, '/')) {
			$path = substr($path, 1);
		}
		if (str_ends_with($path, '/')) {
			$path = substr($path, 0, -1);
		}
		if ($path === '') {
			return [];
		}
		$segments = explode('/', $path);
		if ($decode) {
			$segments = array_map('rawurldecode', $segments);
		}
		foreach ($segments as $segment) {
			if ($segment === '' || $segment === '.' || $segment === '..'
				|| strpbrk($segment, "/\\\0") !== false) {
				throw new NotFoundException('Unsafe reader path segment');
			}
		}
		return $segments;
	}

	public static function assertContained(Node $root, Node $node): void {
		$rootPath = rtrim($root->getPath(), '/');
		$nodePath = $node->getPath();
		if ($rootPath === '' || ($nodePath !== $rootPath && !str_starts_with($nodePath, $rootPath . '/'))) {
			throw new NotFoundException('Reader file is outside the permitted folder');
		}
		// Also reject non-canonical paths returned by an unexpected resolver.
		self::segments($nodePath, false);
	}
}
