<?php

declare(strict_types=1);

namespace OCA\Epubviewer\Service;

use OCP\Files\NotFoundException;

class PublicSharePath {
	/**
	 * Parse a public DAV URL into its share token and share-relative path.
	 *
	 * @return array{token: string, segments: list<string>}|null
	 * @throws NotFoundException
	 */
	public function parse(string $value): ?array {
		$path = parse_url($value, PHP_URL_PATH);
		if (!is_string($path)) {
			$path = $value;
		}

		$segments = $this->splitPathSegments($path);
		$start = $this->findPathSequence($segments, ['public.php', 'dav', 'files']);
		if ($start === null) {
			return null;
		}

		$userDavStart = $this->findPathSequence($segments, ['remote.php', 'dav', 'files']);
		if ($userDavStart !== null && $userDavStart < $start) {
			return null;
		}

		$token = $segments[$start + 3] ?? '';
		if ($token === '') {
			throw new NotFoundException('Share token missing');
		}

		return [
			'token' => $token,
			'segments' => array_slice($segments, $start + 4),
		];
	}

	/**
	 * @return list<string>
	 */
	private function splitPathSegments(string $path): array {
		$path = trim($path, '/');
		if ($path === '') {
			return [];
		}

		return array_map(static fn (string $segment): string => rawurldecode($segment), explode('/', $path));
	}

	/**
	 * @param list<string> $segments
	 * @param list<string> $sequence
	 */
	private function findPathSequence(array $segments, array $sequence): ?int {
		$sequenceLength = count($sequence);
		$limit = count($segments) - $sequenceLength;
		if ($limit < 0) {
			return null;
		}

		for ($start = 0; $start <= $limit; $start++) {
			if (array_slice($segments, $start, $sequenceLength) === $sequence) {
				return $start;
			}
		}

		return null;
	}
}
