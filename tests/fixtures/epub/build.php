<?php

declare(strict_types=1);

namespace OCA\Epubviewer\Tests\Fixtures\Epub;

/**
 * Build the committed EPUB fixture corpus without depending on a ZIP utility.
 *
 * The intentionally small ZIP writer uses stored entries, fixed DOS timestamps,
 * and fixed metadata so its output is byte-for-byte reproducible.
 *
 * @return list<string> generated fixture basenames
 */
function buildFixtures(string $outputDirectory): array {
	if (!is_dir($outputDirectory)
		&& !mkdir($outputDirectory, 0777, true)
		&& !is_dir($outputDirectory)) {
		throw new \RuntimeException(sprintf('Could not create fixture directory "%s".', $outputDirectory));
	}

	$generated = [];
	foreach (fixtureDefinitions() as $basename => $entries) {
		$archive = buildStoredZip($entries);
		$path = rtrim($outputDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $basename;
		$written = file_put_contents($path, $archive);
		if ($written !== strlen($archive)) {
			throw new \RuntimeException(sprintf('Could not write fixture "%s".', $path));
		}

		$generated[] = $basename;
	}

	return $generated;
}

/**
 * @return array<string, list<array{name: string, data: string}>>
 */
function fixtureDefinitions(): array {
	$pngCover = fixturePngCover();
	$jpegCover = fixtureJpegCover();

	return [
		'epub2-metadata-cover.epub' => publicationEntries(
			containerXml('OEBPS/content.opf'),
			[
				[
					'name' => 'OEBPS/content.opf',
					'data' => <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<package xmlns="http://www.idpf.org/2007/opf" unique-identifier="book-id" version="2.0">
  <metadata xmlns:dc="http://purl.org/dc/elements/1.1/">
    <dc:identifier id="book-id">urn:uuid:00000000-0000-4000-8000-000000000201</dc:identifier>
    <dc:title>EPUB 2 metadata cover fixture</dc:title>
    <dc:language>en</dc:language>
    <meta name="cover" content="cover-image"/>
  </metadata>
  <manifest>
    <item id="cover-image" href="images/cover.png" media-type="image/png"/>
    <item id="chapter" href="text/chapter.xhtml" media-type="application/xhtml+xml"/>
    <item id="ncx" href="toc.ncx" media-type="application/x-dtbncx+xml"/>
  </manifest>
  <spine toc="ncx"><itemref idref="chapter"/></spine>
</package>
XML,
				],
				['name' => 'OEBPS/images/cover.png', 'data' => $pngCover],
				['name' => 'OEBPS/text/chapter.xhtml', 'data' => chapterXhtmlV2('EPUB 2 metadata cover fixture')],
				['name' => 'OEBPS/toc.ncx', 'data' => ncxXml('00000000-0000-4000-8000-000000000201')],
			],
		),
		'epub3-cover-image-properties.epub' => publicationEntries(
			containerXml('EPUB/package.opf'),
			[
				[
					'name' => 'EPUB/package.opf',
					'data' => <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<package xmlns="http://www.idpf.org/2007/opf" unique-identifier="book-id" version="3.0" prefix="fixture: https://example.invalid/epubviewer-fixture#">
  <metadata xmlns:dc="http://purl.org/dc/elements/1.1/">
    <dc:identifier id="book-id">urn:uuid:00000000-0000-4000-8000-000000000301</dc:identifier>
    <dc:title>EPUB 3 cover-image properties fixture</dc:title>
    <dc:language>en</dc:language>
    <meta property="dcterms:modified">2000-01-01T00:00:00Z</meta>
    <meta name="cover" content="stale-legacy-cover"/>
  </metadata>
  <manifest>
    <item id="cover" href="assets/cover.jpg" media-type="image/jpeg" properties="fixture:sample cover-image"/>
    <item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>
    <item id="chapter" href="chapter.xhtml" media-type="application/xhtml+xml"/>
  </manifest>
  <spine><itemref idref="chapter"/></spine>
</package>
XML,
				],
				['name' => 'EPUB/assets/cover.jpg', 'data' => $jpegCover],
				['name' => 'EPUB/nav.xhtml', 'data' => navigationXhtml()],
				['name' => 'EPUB/chapter.xhtml', 'data' => chapterXhtml('EPUB 3 cover-image properties fixture')],
			],
		),
		'nested-percent-encoded-cover.epub' => publicationEntries(
			containerXml('Books/My%20Book/package.opf'),
			[
				[
					'name' => 'Books/My Book/package.opf',
					'data' => <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<package xmlns="http://www.idpf.org/2007/opf" unique-identifier="book-id" version="3.0">
  <metadata xmlns:dc="http://purl.org/dc/elements/1.1/">
    <dc:identifier id="book-id">urn:uuid:00000000-0000-4000-8000-000000000302</dc:identifier>
    <dc:title>Nested encoded cover fixture</dc:title>
    <dc:language>en</dc:language>
    <meta property="dcterms:modified">2000-01-01T00:00:00Z</meta>
  </metadata>
  <manifest>
    <item id="cover" href="../Artwork/Front%20Cover.png" media-type="image/png" properties="cover-image"/>
    <item id="nav" href="Text/nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>
    <item id="chapter" href="Text/chapter.xhtml" media-type="application/xhtml+xml"/>
  </manifest>
  <spine><itemref idref="chapter"/></spine>
</package>
XML,
				],
				['name' => 'Books/Artwork/Front Cover.png', 'data' => $pngCover],
				['name' => 'Books/My Book/Text/nav.xhtml', 'data' => navigationXhtml()],
				['name' => 'Books/My Book/Text/chapter.xhtml', 'data' => chapterXhtml('Nested encoded cover fixture')],
			],
		),
		'epub3-large-chapter-small-cover.epub' => publicationEntries(
			containerXml('EPUB/package.opf'),
			[
				[
					'name' => 'EPUB/package.opf',
					'data' => <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<package xmlns="http://www.idpf.org/2007/opf" unique-identifier="book-id" version="3.0">
  <metadata xmlns:dc="http://purl.org/dc/elements/1.1/">
    <dc:identifier id="book-id">urn:uuid:00000000-0000-4000-8000-000000000304</dc:identifier>
    <dc:title>Large chapter with a small cover fixture</dc:title>
    <dc:language>en</dc:language>
    <meta property="dcterms:modified">2000-01-01T00:00:00Z</meta>
  </metadata>
  <manifest>
    <item id="cover" href="images/cover.png" media-type="image/png" properties="cover-image"/>
    <item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>
    <item id="chapter" href="chapter.xhtml" media-type="application/xhtml+xml"/>
  </manifest>
  <spine><itemref idref="chapter"/></spine>
</package>
XML,
				],
				['name' => 'EPUB/images/cover.png', 'data' => $pngCover],
				['name' => 'EPUB/nav.xhtml', 'data' => navigationXhtml()],
				['name' => 'EPUB/chapter.xhtml', 'data' => largeChapterXhtml()],
			],
		),
		'no-cover.epub' => publicationEntries(
			containerXml('EPUB/package.opf'),
			[
				[
					'name' => 'EPUB/package.opf',
					'data' => <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<package xmlns="http://www.idpf.org/2007/opf" unique-identifier="book-id" version="3.0">
  <metadata xmlns:dc="http://purl.org/dc/elements/1.1/">
    <dc:identifier id="book-id">urn:uuid:00000000-0000-4000-8000-000000000303</dc:identifier>
    <dc:title>Publication without a cover</dc:title>
    <dc:language>en</dc:language>
    <meta property="dcterms:modified">2000-01-01T00:00:00Z</meta>
  </metadata>
  <manifest>
    <item id="illustration" href="images/illustration.png" media-type="image/png"/>
    <item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>
    <item id="chapter" href="chapter.xhtml" media-type="application/xhtml+xml"/>
  </manifest>
  <spine><itemref idref="chapter"/></spine>
</package>
XML,
				],
				['name' => 'EPUB/images/illustration.png', 'data' => $pngCover],
				['name' => 'EPUB/nav.xhtml', 'data' => navigationXhtml()],
				['name' => 'EPUB/chapter.xhtml', 'data' => chapterXhtml('Publication without a cover')],
			],
		),
		'rejected-dtd.epub' => publicationEntries(
			<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE container [
  <!ENTITY external SYSTEM "https://example.invalid/epubviewer-fixture.dtd">
]>
<container xmlns="urn:oasis:names:tc:opendocument:xmlns:container" version="1.0">
  <rootfiles>
    <rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/>
  </rootfiles>
  &external;
</container>
XML,
			[
				['name' => 'OEBPS/content.opf', 'data' => basicPackageXml('00000000-0000-4000-8000-00000000d701', 'images/cover.png')],
				['name' => 'OEBPS/images/cover.png', 'data' => $pngCover],
			],
		),
		'rejected-external-cover.epub' => publicationEntries(
			containerXml('OEBPS/content.opf'),
			[
				['name' => 'OEBPS/content.opf', 'data' => basicPackageXml('00000000-0000-4000-8000-00000000e701', 'https://example.invalid/cover.png')],
			],
		),
		'rejected-escaping-cover.epub' => publicationEntries(
			containerXml('OEBPS/content.opf'),
			[
				['name' => 'OEBPS/content.opf', 'data' => basicPackageXml('00000000-0000-4000-8000-00000000e702', '../../outside.png')],
				['name' => 'outside.png', 'data' => $pngCover],
			],
		),
	];
}

/**
 * @param list<array{name: string, data: string}> $publicationEntries
 * @return list<array{name: string, data: string}>
 */
function publicationEntries(string $container, array $publicationEntries): array {
	return [
		['name' => 'mimetype', 'data' => 'application/epub+zip'],
		['name' => 'META-INF/container.xml', 'data' => $container],
		...$publicationEntries,
	];
}

function containerXml(string $packagePath): string {
	return sprintf(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<container xmlns="urn:oasis:names:tc:opendocument:xmlns:container" version="1.0">
  <rootfiles>
    <rootfile full-path="%s" media-type="application/oebps-package+xml"/>
  </rootfiles>
</container>
XML, $packagePath);
}

function basicPackageXml(string $uuid, string $coverHref): string {
	return sprintf(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<package xmlns="http://www.idpf.org/2007/opf" unique-identifier="book-id" version="3.0">
  <metadata xmlns:dc="http://purl.org/dc/elements/1.1/">
    <dc:identifier id="book-id">urn:uuid:fixture-%s</dc:identifier>
    <dc:title>Rejected fixture</dc:title>
    <dc:language>en</dc:language>
    <meta property="dcterms:modified">2000-01-01T00:00:00Z</meta>
  </metadata>
  <manifest>
    <item id="cover" href="%s" media-type="image/png" properties="cover-image"/>
  </manifest>
  <spine/>
</package>
XML, $uuid, htmlspecialchars($coverHref, ENT_XML1 | ENT_QUOTES, 'UTF-8'));
}

function chapterXhtmlV2(string $title): string {
	return sprintf(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.1//EN" "http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en">
  <head><title>%1$s</title></head>
  <body><h1>%1$s</h1><p>Fixture publication content.</p></body>
</html>
XML, htmlspecialchars($title, ENT_XML1 | ENT_QUOTES, 'UTF-8'));
}

function chapterXhtml(string $title): string {
	return sprintf(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" lang="en">
  <head><title>%1$s</title></head>
  <body><h1>%1$s</h1><p>Fixture publication content.</p></body>
</html>
XML, htmlspecialchars($title, ENT_XML1 | ENT_QUOTES, 'UTF-8'));
}

function largeChapterXhtml(): string {
	$paragraph = str_repeat(
		'This unrelated chapter is intentionally larger than the preview entry limit. ',
		80,
	);

	return sprintf(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" lang="en">
  <head><title>Large unrelated chapter</title></head>
  <body><h1>Large unrelated chapter</h1><p>%s</p></body>
</html>
XML, $paragraph);
}

function navigationXhtml(): string {
	return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops" lang="en">
  <head><title>Contents</title></head>
  <body><nav epub:type="toc"><ol><li><a href="chapter.xhtml">Chapter</a></li></ol></nav></body>
</html>
XML;
}

function ncxXml(string $identifier): string {
	return sprintf(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ncx xmlns="http://www.daisy.org/z3986/2005/ncx/" version="2005-1">
  <head>
    <meta name="dtb:uid" content="urn:uuid:%s"/>
    <meta name="dtb:depth" content="1"/>
    <meta name="dtb:totalPageCount" content="0"/>
    <meta name="dtb:maxPageNumber" content="0"/>
  </head>
  <docTitle><text>Fixture</text></docTitle>
  <navMap><navPoint id="chapter" playOrder="1"><navLabel><text>Chapter</text></navLabel><content src="text/chapter.xhtml"/></navPoint></navMap>
</ncx>
XML, $identifier);
}

function fixturePngCover(): string {
	$data = base64_decode(
		'iVBORw0KGgoAAAANSUhEUgAAAAIAAAADCAIAAAD91JpzAAAAFElEQVR42mP4z8DAwMDAxMDAwMAAAAwAAf2K6LkAAAAASUVORK5CYII=',
		true,
	);
	if (!is_string($data)) {
		throw new \LogicException('The embedded PNG fixture is invalid.');
	}

	return $data;
}

function fixtureJpegCover(): string {
	$data = base64_decode(
		'/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAEf/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPwF//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPwF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQAGPwJ//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPyF//9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/EH//xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/EH//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/EH//2Q==',
		true,
	);
	if (!is_string($data)) {
		throw new \LogicException('The embedded JPEG fixture is invalid.');
	}

	return $data;
}

/**
 * @param list<array{name: string, data: string}> $entries
 */
function buildStoredZip(array $entries): string {
	if ($entries === [] || $entries[0] !== ['name' => 'mimetype', 'data' => 'application/epub+zip']) {
		throw new \InvalidArgumentException('An EPUB must start with its uncompressed mimetype entry.');
	}

	$localRecords = '';
	$centralRecords = '';
	$offset = 0;
	$seen = [];
	$dosTime = 0;
	$dosDate = ((2000 - 1980) << 9) | (1 << 5) | 1;

	foreach ($entries as $entry) {
		$name = $entry['name'];
		$data = $entry['data'];
		if ($name === '' || str_contains($name, "\0") || isset($seen[$name])) {
			throw new \InvalidArgumentException(sprintf('Invalid or duplicate ZIP entry name "%s".', $name));
		}
		$seen[$name] = true;

		$nameLength = strlen($name);
		$dataLength = strlen($data);
		$crc = crc32($data);
		$flags = 0x0800;
		$method = 0;

		$localHeader = pack(
			'VvvvvvVVVvv',
			0x04034b50,
			20,
			$flags,
			$method,
			$dosTime,
			$dosDate,
			$crc,
			$dataLength,
			$dataLength,
			$nameLength,
			0,
		);
		$localRecord = $localHeader . $name . $data;
		$localRecords .= $localRecord;

		$centralRecords .= pack(
			'VvvvvvvVVVvvvvvVV',
			0x02014b50,
			20,
			20,
			$flags,
			$method,
			$dosTime,
			$dosDate,
			$crc,
			$dataLength,
			$dataLength,
			$nameLength,
			0,
			0,
			0,
			0,
			0,
			$offset,
		) . $name;

		$offset += strlen($localRecord);
	}

	$entryCount = count($entries);
	$endOfCentralDirectory = pack(
		'VvvvvVVv',
		0x06054b50,
		0,
		0,
		$entryCount,
		$entryCount,
		strlen($centralRecords),
		strlen($localRecords),
		0,
	);

	return $localRecords . $centralRecords . $endOfCentralDirectory;
}

if (PHP_SAPI === 'cli'
	&& isset($_SERVER['SCRIPT_FILENAME'])
	&& realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
	$outputDirectory = $argv[1] ?? __DIR__;
	foreach (buildFixtures($outputDirectory) as $fixture) {
		$hash = hash_file('sha256', rtrim($outputDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $fixture);
		if (!is_string($hash)) {
			throw new \RuntimeException(sprintf('Could not hash fixture "%s".', $fixture));
		}

		fwrite(STDOUT, sprintf("%s  %s\n", $hash, $fixture));
	}
}
