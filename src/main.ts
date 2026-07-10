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

function getPublicShareToken(fileUrl: string): string | null {
  let path: string;
  try {
    path = new URL(fileUrl, window.location.origin).pathname;
  } catch {
    return null;
  }

  const segments = path.split('/').filter((segment) => segment !== '');
  const publicDavStart = segments.findIndex(
    (segment, index) =>
      segment === 'public.php' &&
      segments[index + 1] === 'dav' &&
      segments[index + 2] === 'files',
  );

  if (publicDavStart === -1) {
    return null;
  }

  const userDavStart = segments.findIndex(
    (segment, index) =>
      segment === 'remote.php' &&
      segments[index + 1] === 'dav' &&
      segments[index + 2] === 'files',
  );
  if (userDavStart !== -1 && userDavStart < publicDavStart) {
    return null;
  }

  const encodedToken = segments[publicDavStart + 3];
  if (!encodedToken) {
    return null;
  }

  try {
    return decodeURIComponent(encodedToken);
  } catch {
    return null;
  }
}

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
      const file = this.resolvedFilePath;
      const token = getPublicShareToken(file);

      if (token !== null) {
        return generateUrl('/apps/{APP_ID}/public/{token}?file={file}&type={type}', {
          APP_ID,
          token,
          file,
          type: this.mime,
        });
      }

      return generateUrl('/apps/{APP_ID}/?file={file}&type={type}', {
        APP_ID,
        file,
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
