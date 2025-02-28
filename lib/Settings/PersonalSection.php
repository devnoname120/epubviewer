<?php

namespace OCA\Epubviewer\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class PersonalSection implements IIconSection {
	public function __construct(
		private IURLGenerator $urlGenerator,
		private IL10N $l
	) {
	}

	/**
	 * returns the relative path to an 16*16 icon describing the section.
	 *
	 * @return string
	 */
	public function getIcon() {
		return $this->urlGenerator->imagePath('epubviewer', 'app.svg');
	}

	/**
	 * returns the ID of the section. It is supposed to be a lower case string,
	 *
	 * @return string
	 */
	public function getID() {
		return 'epubviewer';
	}

	/**
	 * returns the translated name as it should be displayed
	 *
	 * @return string
	 */
	public function getName() {
		return $this->l->t('EPUB/CBZ/PDF ebook reader');
	}

	/**
	 * returns priority for positioning
	 *
	 * @return int
	 */
	public function getPriority() {
		return 20;
	}
}
