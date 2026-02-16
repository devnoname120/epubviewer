import { generateUrl } from '@nextcloud/router';
import { registerHandler } from '@nextcloud/viewer';
import { loadState } from '@nextcloud/initial-state';
import type { AsyncComponent } from 'vue';

const APP_ID = 'epubviewer';

const cbxMimes = [
  'application/x-cbr',
  'application/x-cbz',
  // 'application/comicbook+7z',
  // 'application/comicbook+ace',
  'application/comicbook+rar',
  'application/comicbook+tar',
  // 'application/comicbook+truecrypt',
  'application/comicbook+zip',
];

const viewerMimes = [
  'application/epub+zip',
  'application/pdf',
  ...cbxMimes,
];

const EpubViewerComponent: AsyncComponent = {
  name: 'EpubViewerComponent',
  props: {
    path: {
      type: String,
      default: '',
    },
    source: {
      type: String,
      default: '',
    },
    mime: {
      type: String,
      default: '',
    },
  },
  computed: {
    filePath(): string {
      return this.path || this.source;
    },
    viewerUrl(): string {
      return generateUrl('/apps/{APP_ID}/?file={file}&type={type}', {
        APP_ID,
        file: this.filePath,
        type: this.mime,
      });
    },
  },
  methods: {
    onLoad() {
      this.$emit('update:loaded', true);
    },
  },
  render(h) {
    return h('iframe', {
      attrs: {
        src: this.viewerUrl,
        title: 'EPUB viewer',
      },
      style: {
        width: '100%',
        height: '100%',
        border: 'none',
      },
      on: {
        load: this.onLoad,
      },
    });
  },
};

const isEpubEnabled = loadState<boolean>(APP_ID, 'enableEpub');
const isPdfEnabled = loadState<boolean>(APP_ID, 'enablePdf');
const isCbxEnabled = loadState<boolean>(APP_ID, 'enableCbx');

const enabledMimes = viewerMimes.filter((mime) => {
  if (mime === 'application/epub+zip') {
    return isEpubEnabled;
  }

  if (mime === 'application/pdf') {
    return isPdfEnabled;
  }

  return isCbxEnabled;
});

if (enabledMimes.length > 0) {
  registerHandler({
    id: APP_ID,
    mimes: enabledMimes,
    component: EpubViewerComponent,
    theme: 'default',
  });
}
