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
    davPath: {
      type: String,
      default: '',
    },
    filename: {
      type: String,
      default: '',
    },
    mime: {
      type: String,
      default: '',
    },
  },
  computed: {
    resolvedFilePath(): string {
      if (this.source) {
        return this.source;
      }

      if (this.davPath) {
        return this.davPath;
      }

      if (this.path) {
        return this.path;
      }

      throw new Error('No usable file URL for epubviewer handler');
    },
    viewerUrl(): string {
      return generateUrl('/apps/{APP_ID}/?file={file}&type={type}', {
        APP_ID,
        file: this.resolvedFilePath,
        type: this.mime,
      });
    },
  },
  methods: {
    focusFrame(frame: HTMLIFrameElement) {
      if (!frame.isConnected) {
        return;
      }

      if (!frame.hasAttribute('tabindex')) {
        frame.setAttribute('tabindex', '-1');
      }

      // Keep keyboard navigation working immediately after opening the file.
      // Without explicit focus, arrow keys are handled by the outer Viewer page.
      frame.focus();
      frame.contentWindow?.focus();
    },
    onLoad(event: Event) {
      const frame = event.target instanceof HTMLIFrameElement ? event.target : null;
      this.$emit('update:loaded', true);

      if (frame) {
        // Defer once so Viewer post-load updates settle first.
        window.setTimeout(() => this.focusFrame(frame), 0);
      }
    },
  },
  render(h) {
    return h('iframe', {
      attrs: {
        src: this.viewerUrl,
        title: 'EPUB viewer',
        tabindex: '-1',
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

const isEpubEnabled = loadState<boolean>(APP_ID, 'enableEpub', true);
const isPdfEnabled = loadState<boolean>(APP_ID, 'enablePdf', false);
const isCbxEnabled = loadState<boolean>(APP_ID, 'enableCbx', true);

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
  });
}
