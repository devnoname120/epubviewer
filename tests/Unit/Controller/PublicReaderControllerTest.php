<?php

declare(strict_types=1);

namespace OCA\Epubviewer\Tests\Unit\Controller;

use OC\AppFramework\Middleware\PublicShare\PublicShareMiddleware;
use OCA\Epubviewer\Controller\PageController;
use OCA\Epubviewer\Controller\PublicReaderController;
use OCA\Epubviewer\Service\PublicSharePath;
use OCA\Epubviewer\Service\ReaderResponseBuilder;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\PublicShareController;
use OCP\Constants;
use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IRequest;
use OCP\ISession;
use OCP\Security\Bruteforce\IThrottler;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PublicReaderControllerTest extends TestCase {
	private IRequest&MockObject $request;
	private ISession&MockObject $session;
	private IManager&MockObject $shareManager;
	private ReaderResponseBuilder&MockObject $readerResponseBuilder;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->session = $this->createMock(ISession::class);
		$this->shareManager = $this->createMock(IManager::class);
		$this->readerResponseBuilder = $this->createMock(ReaderResponseBuilder::class);
	}

	public function testUnprotectedFileSharePassesMiddlewareAndRenders(): void {
		$token = 'file-token';
		$fileUrl = 'https://cloud.example/public.php/dav/files/file-token';
		$file = $this->createFile('/owner/files/Shared Book.epub', 41);
		$share = $this->createShare($file, null);
		$response = new TemplateResponse('epubviewer', 'epubviewer');

		$this->expectShareLookup($token, $share);
		$this->readerResponseBuilder->expects(self::once())
			->method('build')
			->with($fileUrl, 'application/epub+zip', [
				'fileName' => 'Shared Book',
				'fileType' => 'epub',
				'fileId' => 41,
			])
			->willReturn($response);

		$controller = $this->createController();
		$this->runPublicShareMiddleware($controller, $token);

		self::assertSame(
			$response,
			$controller->showReader($token, $fileUrl, 'application/epub+zip'),
		);
	}

	public function testProtectedShareFailsBeforeFrameworkSessionAuthentication(): void {
		$token = 'protected-token';
		$file = $this->createFile('/owner/files/Hidden.epub', 42);
		$share = $this->createShare($file, 'password-hash');

		$this->expectShareLookup($token, $share);
		$this->session->method('get')
			->with(PublicShareController::DAV_AUTHENTICATED_FRONTEND)
			->willReturn('[]');
		$this->readerResponseBuilder->expects(self::never())->method('build');

		$this->expectException(NotFoundException::class);
		$this->runPublicShareMiddleware($this->createController(), $token);
	}

	public function testProtectedFolderDescendantRendersAfterFrameworkSessionAuthentication(): void {
		$token = 'folder-token';
		$fileUrl = 'https://cloud.example/public.php/dav/files/folder-token/Book%20Shelf/Novel.epub';
		$file = $this->createFile('/owner/files/Library/Book Shelf/Novel.epub', 43);
		$bookShelf = $this->createMock(Folder::class);
		$bookShelf->expects(self::once())
			->method('get')
			->with('Novel.epub')
			->willReturn($file);
		$root = $this->createMock(Folder::class);
		$root->method('isReadable')->willReturn(true);
		$root->expects(self::once())
			->method('get')
			->with('Book Shelf')
			->willReturn($bookShelf);
		$share = $this->createShare($root, 'password-hash');
		$response = new TemplateResponse('epubviewer', 'epubviewer');

		$this->expectShareLookup($token, $share);
		$this->session->method('get')
			->with(PublicShareController::DAV_AUTHENTICATED_FRONTEND)
			->willReturn(json_encode([$token => 'password-hash']));
		$this->readerResponseBuilder->expects(self::once())
			->method('build')
			->with($fileUrl, 'application/epub+zip', [
				'fileName' => 'Novel',
				'fileType' => 'epub',
				'fileId' => 43,
			])
			->willReturn($response);

		$controller = $this->createController();
		$this->runPublicShareMiddleware($controller, $token);

		self::assertSame(
			$response,
			$controller->showReader($token, $fileUrl, 'application/epub+zip'),
		);
	}

	public function testPublicDavUrlCannotBypassMiddlewareThroughUserRoute(): void {
		$fileUrl = 'https://cloud.example/public.php/dav/files/protected-token/Hidden.epub';
		$this->request->method('getParam')->willReturnMap([
			['file', null, $fileUrl],
			['type', null, 'application/epub+zip'],
		]);
		$this->readerResponseBuilder->expects(self::never())->method('build');

		$controller = new PageController(
			'epubviewer',
			$this->request,
			$this->createMock(IRootFolder::class),
			new PublicSharePath(),
			$this->readerResponseBuilder,
			'alice',
		);

		$this->expectException(NotFoundException::class);
		$controller->showReader();
	}

	public function testFileUrlTokenMustMatchGuardedRouteToken(): void {
		$token = 'expected-token';
		$file = $this->createFile('/owner/files/Hidden.epub', 44);
		$share = $this->createShare($file, null);

		$this->expectShareLookup($token, $share);
		$this->readerResponseBuilder->expects(self::never())->method('build');

		$controller = $this->createController();
		$this->runPublicShareMiddleware($controller, $token);

		$this->expectException(NotFoundException::class);
		$controller->showReader(
			$token,
			'https://cloud.example/public.php/dav/files/different-token',
			'application/epub+zip',
		);
	}

	public function testUserDavPathContainingPublicLikeSegmentsIsNotAPublicShare(): void {
		$path = 'https://cloud.example/remote.php/dav/files/alice/archive/public.php/dav/files/not-a-token/Book.epub';

		self::assertNull((new PublicSharePath())->parse($path));
	}

	private function createController(): PublicReaderController {
		return new PublicReaderController(
			'epubviewer',
			$this->request,
			$this->session,
			$this->shareManager,
			new PublicSharePath(),
			$this->readerResponseBuilder,
		);
	}

	private function createFile(string $path, int $fileId): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('isReadable')->willReturn(true);
		$file->method('getType')->willReturn(FileInfo::TYPE_FILE);
		$file->method('getPath')->willReturn($path);
		$file->method('getId')->willReturn($fileId);

		return $file;
	}

	private function createShare(File|Folder $node, ?string $passwordHash): IShare&MockObject {
		$share = $this->createMock(IShare::class);
		$share->method('getNode')->willReturn($node);
		$share->method('getPermissions')->willReturn(Constants::PERMISSION_READ);
		$share->method('getPassword')->willReturn($passwordHash);

		return $share;
	}

	private function expectShareLookup(string $token, IShare $share): void {
		$this->shareManager->expects(self::once())
			->method('getShareByToken')
			->with($token)
			->willReturn($share);
	}

	private function runPublicShareMiddleware(PublicReaderController $controller, string $token): void {
		$this->request->method('getRemoteAddress')->willReturn('192.0.2.1');
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key): ?string => $key === 'token' ? $token : null,
		);

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('yes');
		$middleware = new PublicShareMiddleware(
			$this->request,
			$this->session,
			$config,
			$this->createMock(IThrottler::class),
		);

		$middleware->beforeController($controller, 'showReader');
	}
}
