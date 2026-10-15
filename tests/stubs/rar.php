<?php

declare(strict_types=1);

/** @implements IteratorAggregate<int, RarEntry> */
final class RarArchive implements IteratorAggregate {
	public static function open(string $filename, ?string $password = null, ?callable $volumeCallback = null): self|false {
	}

	public function isSolid(): bool {
	}

	public function isBroken(): bool {
	}

	public function close(): bool {
	}

	/** @return Traversable<int, RarEntry> */
	public function getIterator(): Traversable {
	}
}

final class RarEntry {
	public function getName(): string {
	}

	public function getUnpackedSize(): int {
	}

	public function getPackedSize(): int {
	}

	/** @return resource|false */
	public function getStream(?string $password = null) {
	}

	public function isDirectory(): bool {
	}

	public function isEncrypted(): bool {
	}

	public function getRedirType(): ?int {
	}
}
