<?php

declare(strict_types=1);

namespace OCA\Epubviewer\Listener;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IDBConnection;
use OCP\User\Events\UserDeletedEvent;

/** @template-implements IEventListener<UserDeletedEvent> */
class UserDeletedListener implements IEventListener {
	public function __construct(private IDBConnection $connection) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof UserDeletedEvent)) {
			return;
		}

		$userId = $event->getUser()->getUID();

		// Delete bookmarks
		$queryBuilder = $this->connection->getQueryBuilder();
		$queryBuilder->delete('reader_bookmarks')
			->where($queryBuilder->expr()->eq('user_id', $queryBuilder->createNamedParameter($userId)))
			->executeStatement();

		// Delete preferences
		$queryBuilder = $this->connection->getQueryBuilder();
		$queryBuilder->delete('reader_prefs')
			->where($queryBuilder->expr()->eq('user_id', $queryBuilder->createNamedParameter($userId)))
			->executeStatement();
	}
}
