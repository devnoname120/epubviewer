import { generateUrl } from '@nextcloud/router';
import { DefaultType, FileAction, type Node, Permission, registerFileAction } from '@nextcloud/files';
import { loadState } from '@nextcloud/initial-state';

// TODO: use i10n for strings:
// import { translate as t, translatePlural as n } from '@nextcloud/l10n'

const APP_ID = 'epubviewer';

function hideControls() {
  $('#app-content #controls').hide();
  // and, for NC12...
  $('#app-navigation').css('display', 'none');
}

function hide() {
  if ($('#fileList').length > 0) {
    // FileList.setViewerMode(false);
  }
  $('#controls').show();
  $('#app-content #controls').removeClass('hidden');
  // NC12...
  $('#app-navigation').css('display', '');
  if ($('#isPublic').val()) {
    $('#imgframe').show();
    $('footer').show();
    $('.directLink').show();
    $('.directDownload').show();
  }
  $('iframe').remove();
  $('body').off('focus.filesreader');
  $(window).off('popstate.filesreader');
}

function show(downloadUrl: string, mimeType: string, isFileList: boolean) {
  const viewer = generateUrl('/apps/{APP_ID}/?file={file}&type={type}', { APP_ID, file: downloadUrl, type: mimeType });
  // launch in new window on all devices
  window.open(viewer, downloadUrl);
}

function actionHandler(file: Node, dir: string) {
  let downloadUrl = '';
  if ($('#isPublic').val()) {
    const sharingToken = $('#sharingToken').val();
    downloadUrl = generateUrl('/s/{token}/download?files={files}&path={path}', {
      token: sharingToken,
      files: file.basename,
      path: dir,
    });
  } else {
    downloadUrl = getAbsolutePath(file.encodedSource);
  }
  show(downloadUrl, file.mime || '', true);
}

function getAbsolutePath(url: string): string {
  const urlObj = new URL(url);
  return urlObj.pathname + urlObj.search + urlObj.hash;
}

const isEpubEnabled = loadState<boolean>(APP_ID, 'enableEpub');
const isPdfEnabled = loadState<boolean>(APP_ID, 'enablePdf');
const isCbxEnabled = loadState<boolean>(APP_ID, 'enableCbx');

const nextcloudVersion = Number(window.OC?.config?.versionstring?.split('.')?.[0]) || undefined;

// registerFileAction() was introduced in Nextcloud 28
if (nextcloudVersion === undefined || nextcloudVersion >= 28) {
  registerFileAction(
    new FileAction({
      id: 'view-epub',
      iconSvgInline: () => '<svg></svg>',
      displayName: () => 'View',
      default: DefaultType.DEFAULT,
      enabled(nodes) {
        const isEpub = nodes.some((node) => node.mime === 'application/epub+zip');
        const isReadable = nodes.some((node) => node.permissions & Permission.READ);

        return isEpubEnabled && isEpub && isReadable;
      },
      exec: async function (file, view, dir) {
        actionHandler(file, dir);
        return true;
      },
    }),
  );

  registerFileAction(
    new FileAction({
      id: 'view-cbr',
      iconSvgInline: () => '<svg></svg>',
      displayName: () => 'View',
      default: DefaultType.DEFAULT,
      enabled(nodes) {
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

        const isCbx = nodes.some((node) => cbxMimes.includes(node.mime || ''));
        const isReadable = nodes.some((node) => node.permissions & Permission.READ);

        return isCbxEnabled && isCbx && isReadable;
      },
      exec: async function (file, view, dir) {
        actionHandler(file, dir);
        return true;
      },
    }),
  );

  registerFileAction(
    new FileAction({
      id: 'view-pdf',
      iconSvgInline: () => '<svg></svg>',
      displayName: () => 'View',
      default: DefaultType.DEFAULT,
      enabled(nodes) {
        const isPdf = nodes.some((node) => node.mime === 'application/pdf');
        const isReadable = nodes.some((node) => node.permissions & Permission.READ);

        return isPdfEnabled && isPdf && isReadable;
      },
      exec: async function (file, view, dir) {
        actionHandler(file, dir);
        return true;
      },
    }),
  );
  // TODO: remove me once we stop supporting Nextcloud 27 and below
} else {
  OC.Plugins.register('OCA.Files.FileList', {
    attach: function (fileList) {
      const fileActions = fileList.fileActions;

      fileActions.registerAction({
        name: 'view-epub',
        displayName: 'View',
        mime: 'application/epub+zip',
        permissions: OC.PERMISSION_READ,
        actionHandler: (fileName, context) => {
          const dir = context.dir;
          const file = {
            basename: fileName,
            mime: 'application/epub+zip',
            source: new URL(Files.getDownloadUrl(fileName, context.dir), document.location.href).toString(),
          };

          actionHandler(file, dir);
        },
      });

      const cbxMime = [
        'application/x-cbr',
        'application/comicbook+7z',
        'application/comicbook+ace',
        'application/comicbook+rar',
        'application/comicbook+tar',
        'application/comicbook+truecrypt',
        'application/comicbook+zip',
      ];

      for (const [i, mime] of Object.entries(cbxMime)) {
        fileActions.registerAction({
          name: 'view-cbr-' + i,
          displayName: 'View',
          mime,
          permissions: OC.PERMISSION_READ,
          actionHandler: (fileName, context) => {
            const dir = context.dir;
            const file = {
              basename: fileName,
              mime,
              source: new URL(Files.getDownloadUrl(fileName, context.dir), document.location.href).toString(),
            };

            actionHandler(file, dir);
          },
        });

        if (isCbxEnabled) {
          fileActions.setDefault(mime, 'view-cbr-' + i);
        }
      }

      fileActions.registerAction({
        name: 'view-pdf',
        displayName: 'View',
        mime: 'application/pdf',
        permissions: OC.PERMISSION_READ,
        actionHandler: (fileName, context) => {
          const dir = context.dir;
          const file = {
            basename: fileName,
            mime: 'application/pdf',
            source: new URL(Files.getDownloadUrl(fileName, context.dir), document.location.href).toString(),
          };

          actionHandler(file, dir);
        },
      });

      if (isEpubEnabled) {
        fileActions.setDefault('application/epub+zip', 'view-epub');
      }
      if (isPdfEnabled) {
        fileActions.setDefault('application/pdf', 'view-pdf');
      }
    },
  });
}
