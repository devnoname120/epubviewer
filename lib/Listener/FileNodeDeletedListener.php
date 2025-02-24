<?php

declare(strict_types=1);

namespace OCA\Epubviewer\Listener;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\IDBConnection;

/** @template-implements IEventListener<NodeDeletedEvent> */
class FileNodeDeletedListener implements IEventListener {
	private IDBConnection $connection;

	public function __construct(IDBConnection $connection) {
		$this->connection = $connection;
	}

	public function handle(Event $event): void {
		if (!($event instanceof NodeDeletedEvent)) {
			return;
		}

		$fileId = $event->getNode()->getId();
		
		// Delete bookmarks
		$queryBuilder = $this->connection->getQueryBuilder();
		$queryBuilder->delete('reader_bookmarks')
			->where($queryBuilder->expr()->eq('file_id', $queryBuilder->createNamedParameter($fileId)))
			->executeStatement();

		// Delete preferences
		$queryBuilder = $this->connection->getQueryBuilder();
		$queryBuilder->delete('reader_prefs')
			->where($queryBuilder->expr()->eq('file_id', $queryBuilder->createNamedParameter($fileId)))
			->executeStatement();
	}
}
