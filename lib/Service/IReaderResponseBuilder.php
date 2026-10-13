<?php

declare(strict_types=1);

namespace OCA\Epubviewer\Service;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\Files\NotFoundException;

interface IReaderResponseBuilder {
	/**
	 * @param array{fileName: string, fileType: string, fileId: int} $fileInfo
	 * @throws NotFoundException
	 */
	public function build(string $file, string $type, array $fileInfo): TemplateResponse;
}
