import { getUnarchiver as getBitjsUnarchiver, UnarchiveEventType } from '../bitjs/v1.2.6/archive/decompress.js';

const archiveModuleUrl = new URL('../epubviewer-rar.mjs', import.meta.url);
archiveModuleUrl.search = new URL(import.meta.url).search;
const {
	createComicUnarchiver,
	createRarUrlUnarchiver,
	createZipUrlUnarchiver,
} = await import(archiveModuleUrl.href);

window.CBRJS = window.CBRJS || {};
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
window.CBRJS.UnarchiveEventType = UnarchiveEventType;
