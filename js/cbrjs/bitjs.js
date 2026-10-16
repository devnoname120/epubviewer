import { getUnarchiver as getBitjsUnarchiver, UnarchiveEventType } from '../bitjs/v1.2.6/archive/decompress.js';

const archiveModuleUrl = new URL('../epubviewer-rar.mjs', import.meta.url);
archiveModuleUrl.search = new URL(import.meta.url).search;

window.CBRJS = window.CBRJS || {};
window.CBRJS.UnarchiveEventType = UnarchiveEventType;
window.CBRJS.archiveReady = import(archiveModuleUrl.href).then(function({
	createComicUnarchiver,
	createRarUrlUnarchiver,
	createZipUrlUnarchiver,
}) {
	window.CBRJS.getUnarchiver = function(arrayBuffer) {
		return createComicUnarchiver(arrayBuffer, {
			nonRarFactory: getBitjsUnarchiver,
		});
	};
	window.CBRJS.getRarUnarchiver = function(url) {
		return createRarUrlUnarchiver(url);
	};
	window.CBRJS.getZipUnarchiver = function(url) {
		return createZipUrlUnarchiver(url);
	};
});
