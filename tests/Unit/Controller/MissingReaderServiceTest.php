<?php

declare(strict_types=1);

namespace OCA\Epubviewer\Tests\Unit\Controller;

use OCA\Epubviewer\Controller\BookmarkController;
use OCA\Epubviewer\Controller\PreferenceController;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

final class MissingReaderServiceTest extends TestCase {
	public function testBookmarkControllerRemainsConstructibleWithoutAUserService(): void {
		$controller = new BookmarkController('epubviewer', $this->createMock(IRequest::class), null);
		self::assertSame([], $controller->get(42, 'position'));
		self::assertNull($controller->getCursor(42));
		$controller->set(42, 'position', '0');
		$controller->setCursor(42, '0');
		$controller->delete(42, 'position');
		$controller->deleteCursor(42);
	}

	public function testPreferenceControllerHandlesAnUnavailableUserService(): void {
		$controller = new PreferenceController('epubviewer', $this->createMock(IRequest::class), null, 'alice');
		foreach ([$controller->get('epubviewer', 42, 'font_size'), $controller->getDefault('epubviewer', 'font_size')] as $response) {
			self::assertInstanceOf(JSONResponse::class, $response);
			self::assertSame([], $response->getData());
		}
		$controller->set('epubviewer', 42, 'font_size', '0');
		$controller->setDefault('epubviewer', 'font_size', '0');
		$controller->delete('epubviewer', 42, 'font_size');
		$controller->deleteDefault('epubviewer', 'font_size');
	}
}
