import { getUnarchiver as getBitjsUnarchiver, UnarchiveEventType } from '../bitjs/v1.2.6/archive/decompress.js';
import { createComicUnarchiver, createRarUrlUnarchiver } from '../epubviewer-rar.mjs';

window.CBRJS = window.CBRJS || {};
window.CBRJS.getUnarchiver = function(arrayBuffer) {
	return createComicUnarchiver(arrayBuffer, {
		nonRarFactory: getBitjsUnarchiver,
	});
};
window.CBRJS.getRarUnarchiver = function(url) {
	return createRarUrlUnarchiver(url);
};
window.CBRJS.UnarchiveEventType = UnarchiveEventType;
