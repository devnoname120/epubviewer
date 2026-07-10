<?php

declare(strict_types=1);

namespace OCA\Epubviewer\Preview;

use DOMDocument;
use DOMElement;
use DOMXPath;
use ZipArchive;

/**
 * Reads only the bounded subset of an EPUB needed to locate its cover image.
 */
final class EPubArchiveReader {
	private const CONTAINER_PATH = 'META-INF/container.xml';
	private const CONTAINER_NAMESPACE = 'urn:oasis:names:tc:opendocument:xmlns:container';
	private const PACKAGE_MEDIA_TYPE = 'application/oebps-package+xml';
	private const PACKAGE_NAMESPACE = 'http://www.idpf.org/2007/opf';
	private const MAX_ENTRY_NAME_BYTES = 4096;

	private EPubArchiveLimits $limits;

	public function __construct(?EPubArchiveLimits $limits = null) {
		$this->limits = $limits ?? new EPubArchiveLimits();
	}

	public function getMaxArchiveBytes(): int {
		return $this->limits->maxArchiveBytes;
	}

	/**
	 * @return null|array{data: string, mime: string, path: string}
	 */
	public function readCover(string $file): ?array {
		$this->assertArchiveFileSize($file);

		$zip = new ZipArchive();
		$result = $zip->open($file, ZipArchive::RDONLY | ZipArchive::CHECKCONS);
		if ($result !== true) {
			throw new \UnexpectedValueException('The EPUB is not a consistent ZIP archive.');
		}

		try {
			$totalReadBytes = 0;
			$entries = $this->inspectEntries($zip);
			$containerEntry = $entries[self::CONTAINER_PATH] ?? null;
			if ($containerEntry === null) {
				throw new \UnexpectedValueException('The EPUB does not contain META-INF/container.xml.');
			}

			$containerXml = $this->readEntry(
				$zip,
				$containerEntry,
				$this->limits->maxContainerXmlBytes,
				'container document',
				$totalReadBytes,
			);
			$container = $this->loadXml($containerXml, 'container document');
			$packagePath = $this->findPackagePath($container);
			$packageEntry = $entries[$packagePath] ?? null;
			if ($packageEntry === null) {
				throw new \UnexpectedValueException('The package document declared by the EPUB is missing.');
			}

			$packageXml = $this->readEntry(
				$zip,
				$packageEntry,
				$this->limits->maxPackageDocumentBytes,
				'package document',
				$totalReadBytes,
			);
			$package = $this->loadXml($packageXml, 'package document');
			$cover = $this->findCoverReference($package, $packagePath);
			if ($cover === null) {
				return null;
			}

			$coverEntry = $entries[$cover['path']] ?? null;
			if ($coverEntry === null) {
				return null;
			}

			return [
				'data' => $this->readEntry(
					$zip,
					$coverEntry,
					$this->limits->maxCoverBytes,
					'cover image',
					$totalReadBytes,
				),
				'mime' => $cover['mime'],
				'path' => $cover['path'],
			];
		} finally {
			$zip->close();
		}
	}

	private function assertArchiveFileSize(string $file): void {
		if (!is_file($file) || !is_readable($file)) {
			throw new \UnexpectedValueException('The EPUB file is unavailable.');
		}

		$size = filesize($file);
		if ($size === false) {
			throw new \UnexpectedValueException('The EPUB file size could not be determined.');
		}

		if ($size < 1) {
			throw new \UnexpectedValueException('The EPUB file is empty.');
		}

		if ($size > $this->limits->maxArchiveBytes) {
			throw new \UnexpectedValueException(sprintf(
				'The EPUB exceeds the %d-byte preview input limit.',
				$this->limits->maxArchiveBytes,
			));
		}
	}

	/**
	 * @return array<string, array{name: string, size: int, compressedSize: int}>
	 */
	private function inspectEntries(ZipArchive $zip): array {
		if ($zip->numFiles > $this->limits->maxEntryCount) {
			throw new \UnexpectedValueException(sprintf(
				'The EPUB contains more than %d archive entries.',
				$this->limits->maxEntryCount,
			));
		}

		$entries = [];
		for ($index = 0; $index < $zip->numFiles; ++$index) {
			$stat = $zip->statIndex($index);
			if ($stat === false
				|| !isset($stat['name'], $stat['size'], $stat['comp_size'])
				|| !is_string($stat['name'])
				|| !is_int($stat['size'])
				|| !is_int($stat['comp_size'])) {
				throw new \UnexpectedValueException('The EPUB contains an unreadable archive entry.');
			}

			$name = $stat['name'];
			$size = $stat['size'];
			$compressedSize = $stat['comp_size'];

			if ($name === '' || strlen($name) > self::MAX_ENTRY_NAME_BYTES || str_contains($name, "\0")) {
				throw new \UnexpectedValueException('The EPUB contains an invalid archive entry name.');
			}

			if (isset($entries[$name])) {
				throw new \UnexpectedValueException('The EPUB contains duplicate archive entry names.');
			}

			if ($size < 0 || $compressedSize < 0) {
				throw new \UnexpectedValueException('The EPUB contains an archive entry with an invalid size.');
			}

			$entries[$name] = [
				'name' => $name,
				'size' => $size,
				'compressedSize' => $compressedSize,
			];
		}

		return $entries;
	}

	/**
	 * @param array{name: string, size: int, compressedSize: int} $entry
	 */
	private function readEntry(
		ZipArchive $zip,
		array $entry,
		int $maxBytes,
		string $description,
		int &$totalReadBytes,
	): string {
		if ($entry['size'] > $this->limits->maxEntryUncompressedBytes) {
			throw new \UnexpectedValueException(sprintf(
				'The EPUB %s exceeds the per-entry preview limit.',
				$description,
			));
		}

		if ($entry['size'] > $maxBytes) {
			throw new \UnexpectedValueException(sprintf(
				'The EPUB %s exceeds the %d-byte preview limit.',
				$description,
				$maxBytes,
			));
		}

		if ($entry['size'] > $this->limits->maxTotalUncompressedBytes - $totalReadBytes) {
			throw new \UnexpectedValueException('The EPUB exceeds the cumulative uncompressed preview limit.');
		}

		if ($entry['size'] >= $this->limits->minCompressionRatioBytes
			&& ($entry['compressedSize'] === 0
				|| $entry['size'] > $entry['compressedSize'] * $this->limits->maxCompressionRatio)) {
			throw new \UnexpectedValueException(sprintf(
				'The EPUB %s exceeds the allowed compression ratio.',
				$description,
			));
		}

		$stream = $zip->getStream($entry['name']);
		if ($stream === false) {
			throw new \UnexpectedValueException(sprintf('The EPUB %s could not be opened.', $description));
		}

		try {
			$data = stream_get_contents($stream, $maxBytes + 1);
		} finally {
			fclose($stream);
		}

		if ($data === false) {
			throw new \UnexpectedValueException(sprintf('The EPUB %s could not be read.', $description));
		}

		if (strlen($data) > $maxBytes || strlen($data) !== $entry['size']) {
			throw new \UnexpectedValueException(sprintf('The EPUB %s has an inconsistent size.', $description));
		}
		$totalReadBytes += strlen($data);

		return $data;
	}

	private function loadXml(string $xml, string $description): DOMDocument {
		if ($xml === '') {
			throw new \UnexpectedValueException(sprintf('The EPUB %s is empty.', $description));
		}

		if (preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i', $xml) === 1) {
			throw new \UnexpectedValueException(sprintf('The EPUB %s contains a prohibited DTD or entity declaration.', $description));
		}

		$document = new DOMDocument();
		$loaded = $document->loadXML(
			$xml,
			LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT,
		);
		if (!$loaded || $document->doctype !== null) {
			throw new \UnexpectedValueException(sprintf('The EPUB %s is not valid XML.', $description));
		}

		return $document;
	}

	private function findPackagePath(DOMDocument $container): string {
		$root = $container->documentElement;
		if (!$root instanceof DOMElement || $root->localName !== 'container') {
			throw new \UnexpectedValueException('The EPUB container document has an invalid root element.');
		}

		$xpath = new DOMXPath($container);
		if ($root->namespaceURI === self::CONTAINER_NAMESPACE) {
			$xpath->registerNamespace('ocf', self::CONTAINER_NAMESPACE);
			$expression = sprintf(
				'/ocf:container/ocf:rootfiles/ocf:rootfile[@media-type="%s"]/@full-path',
				self::PACKAGE_MEDIA_TYPE,
			);
		} elseif ($root->namespaceURI === null || $root->namespaceURI === '') {
			// Retain compatibility with older malformed books that omitted the
			// container namespace, without accepting foreign-namespace decoys.
			$expression = sprintf(
				'/container/rootfiles/rootfile[@media-type="%s"]/@full-path',
				self::PACKAGE_MEDIA_TYPE,
			);
		} else {
			throw new \UnexpectedValueException('The EPUB container document uses an invalid namespace.');
		}

		$nodes = $xpath->query($expression);
		if ($nodes === false) {
			throw new \UnexpectedValueException('The EPUB container could not be read.');
		}
		$path = $nodes->item(0)?->nodeValue;
		if (!is_string($path) || trim($path) === '') {
			throw new \UnexpectedValueException('The EPUB container does not declare a package document.');
		}

		return $this->normalizeArchivePath(rawurldecode(trim($path)));
	}

	/**
	 * @return null|array{mime: string, path: string}
	 */
	private function findCoverReference(DOMDocument $package, string $packagePath): ?array {
		$root = $package->documentElement;
		if (!$root instanceof DOMElement || $root->localName !== 'package') {
			throw new \UnexpectedValueException('The EPUB package document has an invalid root element.');
		}

		$xpath = new DOMXPath($package);
		if ($root->namespaceURI === self::PACKAGE_NAMESPACE) {
			$xpath->registerNamespace('opf', self::PACKAGE_NAMESPACE);
			$coverIdExpression = '/opf:package/opf:metadata/opf:meta[@name="cover"]/@content';
			$manifestExpression = '/opf:package/opf:manifest/opf:item';
		} elseif ($root->namespaceURI === null || $root->namespaceURI === '') {
			// Preserve compatibility with legacy package documents that omitted
			// the OPF namespace while still excluding foreign-namespace elements.
			$coverIdExpression = '/package/metadata/meta[@name="cover"]/@content';
			$manifestExpression = '/package/manifest/item';
		} else {
			throw new \UnexpectedValueException('The EPUB package document uses an invalid namespace.');
		}

		$coverIdNodes = $xpath->query($coverIdExpression);
		if ($coverIdNodes === false) {
			throw new \UnexpectedValueException('The EPUB package metadata could not be read.');
		}
		$coverId = $coverIdNodes->item(0)?->nodeValue;

		$manifestItems = $xpath->query($manifestExpression);
		if ($manifestItems === false) {
			throw new \UnexpectedValueException('The EPUB package manifest could not be read.');
		}

		$legacyCover = null;
		$epub3Cover = null;
		foreach ($manifestItems as $item) {
			if (!$item instanceof DOMElement) {
				continue;
			}

			if ($legacyCover === null
				&& is_string($coverId)
				&& $coverId !== ''
				&& $item->getAttribute('id') === $coverId) {
				$legacyCover = $item;
			}

			$properties = preg_split('/\s+/', trim($item->getAttribute('properties')));
			if ($epub3Cover === null && is_array($properties) && in_array('cover-image', $properties, true)) {
				$epub3Cover = $item;
			}
		}

		$isEpub3 = preg_match('/^3(?:\.|$)/', trim($root->getAttribute('version'))) === 1;
		$candidates = $isEpub3
			? [$epub3Cover, $legacyCover]
			: [$legacyCover, $epub3Cover];

		foreach ($candidates as $candidate) {
			if (!$candidate instanceof DOMElement) {
				continue;
			}

			$reference = $this->coverReferenceFromManifestItem($candidate, $packagePath);
			if ($reference !== null) {
				return $reference;
			}
		}

		return null;
	}

	/**
	 * @return null|array{mime: string, path: string}
	 */
	private function coverReferenceFromManifestItem(DOMElement $item, string $packagePath): ?array {
		$href = $item->getAttribute('href');
		if ($href === '') {
			return null;
		}

		return [
			'mime' => $item->getAttribute('media-type'),
			'path' => $this->resolveArchivePath($packagePath, $href),
		];
	}

	private function resolveArchivePath(string $packagePath, string $href): string {
		if (str_contains($href, "\0")
			|| str_contains($href, '\\')
			|| str_starts_with($href, '/')
			|| str_starts_with($href, '//')
			|| preg_match('/^[a-z][a-z0-9+.-]*:/i', $href) === 1) {
			throw new \UnexpectedValueException('The EPUB cover reference is not a safe archive-relative path.');
		}

		$pathWithoutQuery = preg_split('/[?#]/', $href, 2)[0] ?? '';
		$basePath = dirname($packagePath);
		$combinedPath = ($basePath === '.' ? '' : $basePath . '/') . rawurldecode($pathWithoutQuery);

		return $this->normalizeArchivePath($combinedPath);
	}

	private function normalizeArchivePath(string $path): string {
		if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\') || str_starts_with($path, '/')) {
			throw new \UnexpectedValueException('The EPUB contains an invalid archive-relative path.');
		}

		$parts = [];
		foreach (explode('/', $path) as $part) {
			if ($part === '' || $part === '.') {
				continue;
			}

			if ($part === '..') {
				if ($parts === []) {
					throw new \UnexpectedValueException('The EPUB archive path escapes its root.');
				}
				array_pop($parts);
				continue;
			}

			$parts[] = $part;
		}

		if ($parts === []) {
			throw new \UnexpectedValueException('The EPUB contains an empty archive-relative path.');
		}

		return implode('/', $parts);
	}
}
