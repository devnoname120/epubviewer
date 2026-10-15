<?php

declare(strict_types=1);

namespace OCA\Epubviewer\Preview;

use Psr\Log\LoggerInterface;

final class CBZPreview extends ComicPreview {
	public function __construct(LoggerInterface $logger) {
		parent::__construct($logger, new CBZArchiveReader());
	}

	public function getMimeType(): string {
		return '/^(?:application\/x-cbz|application\/comicbook\+zip)$/';
	}
}
