import {generateUrl} from '@nextcloud/router';
import {DefaultType, FileAction, type Node, Permission, registerFileAction} from '@nextcloud/files';
import { loadState } from '@nextcloud/initial-state'

// TODO: use i10n for strings:
// import { translate as t, translatePlural as n } from '@nextcloud/l10n'

const APP_ID = 'epubviewer'

function hideControls () {
  $('#app-content #controls').hide();
  // and, for NC12...
  $('#app-navigation').css('display', 'none');
}

function hide () {
  if ($('#fileList').length) {
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

function show (downloadUrl: string, mimeType: string, isFileList: boolean) {
  const viewer = generateUrl('/apps/{APP_ID}/?file={file}&type={type}', { APP_ID, file: downloadUrl, type: mimeType });
  // launch in new window on all devices
  window.open(viewer, downloadUrl);
}

function actionHandler (file: Node, dir: string) {
  let downloadUrl = '';
  if ($('#isPublic').val()) {
    const sharingToken = $('#sharingToken').val();
    downloadUrl = generateUrl('/s/{token}/download?files={files}&path={path}', {
      token: sharingToken,
      files: file.basename,
      path: dir
    });
  } else {

    downloadUrl = getAbsolutePath(file.source);
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

registerFileAction(new FileAction({
  id: 'view-epub',
  iconSvgInline: () => '<svg></svg>',
  displayName: () => 'View',
  default: DefaultType.DEFAULT,
  enabled (nodes) {
    const isEpub = nodes.some(node => node.mime === 'application/epub+zip')
    const isReadable = nodes.some(node => node.permissions & Permission.READ);

    return isEpubEnabled && isEpub && isReadable;
  },
  exec: async function (file, view, dir) {
    actionHandler(file, dir);
    return true;
  }
}));

registerFileAction(new FileAction({
  id: 'view-cbr',
  iconSvgInline: () => '<svg></svg>',
  displayName: () => 'View',
  default: DefaultType.DEFAULT,
  enabled (nodes) {
    const cbxMimes = [
      'application/x-cbr',
      'application/comicbook+7z',
      'application/comicbook+ace',
      'application/comicbook+rar',
      'application/comicbook+tar',
      'application/comicbook+truecrypt',
      'application/comicbook+zip'
    ];

    const isCbx = nodes.some(node => cbxMimes.includes(node.mime || ''))
    const isReadable = nodes.some(node => node.permissions & Permission.READ);

    return isCbxEnabled && isCbx && isReadable;
  },
  exec: async function (file, view, dir) {
    actionHandler(file, dir);
    return true;
  }
}));

registerFileAction(new FileAction({
  id: 'view-pdf',
  iconSvgInline: () => '<svg></svg>',
  displayName: () => 'View',
  default: DefaultType.DEFAULT,
  enabled (nodes) {
    const isPdf = nodes.some(node => node.mime === 'application/pdf')
    const isReadable = nodes.some(node => node.permissions & Permission.READ);

    return isPdfEnabled && isPdf && isReadable;
  },
  exec: async function (file, view, dir) {
    actionHandler(file, dir);
    return true;
  }
}));

// // FIXME: Hack for single public file view since it is not attached to the fileslist
// window.addEventListener('DOMContentLoaded', function () {
//     var mime = $('#mimetype').val();
//
//     var supported_mimetypes = ['application/epub+zip', 'application/pdf', 'application/x-cbr'];
//     if (!$('#isPublic').val() || !supported_mimetypes.includes(mime)
//     ) {
//         return;
//     }
//
//     var sharingToken = $('#sharingToken').val();
//     var downloadUrl = generateUrl('/s/{token}/download', {token: sharingToken});
//
//     var content = $('#files-public-content');
//     var footerElmt = document.querySelector('body > footer') || document.querySelector('#app-content > footer')
//     var mainContent = document.querySelector('#content')
//
//     var viewerUrl = generateUrl('/apps/epubviewer/?file={file}&type={type}', {file: downloadUrl, type: mime});
//
//     // Create viewer frame
//     const viewerNode = document.createElement('iframe')
//     viewerNode.style.height = '100%'
//     viewerNode.style.width = '100%'
//     viewerNode.style.position = 'absolute'
//
//     // Inject viewer
//     content.empty()
//     content.append(viewerNode)
//     viewerNode.src = viewerUrl
//     footerElmt.style.display = 'none'
//     mainContent.style.minHeight = 'calc(100% - var(--header-height))' // Make the viewer take the whole height as the footer is now hidden.
//     // overwrite style in order to fix the viewer on public pages
//     mainContent.style.marginLeft = '0'
//     mainContent.style.marginRight = '0'
//     mainContent.style.width = '100%'
//     mainContent.style.borderRadius = 'unset'
// });
