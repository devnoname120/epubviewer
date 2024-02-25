import { generateFilePath, generateUrl } from '@nextcloud/router';
import { FileAction, registerFileAction, Permission, type Node } from '@nextcloud/files';

// TODO: use i10n for strings:
// import { translate as t, translatePlural as n } from '@nextcloud/l10n'

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
  const viewer = generateUrl('/apps/epubviewer/?file={file}&type={type}', { file: downloadUrl, type: mimeType });
  // launch in new window on all devices
  window.open(viewer, downloadUrl);
}

function actionHandler (file: Node, mime: string, dir: string) {
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
  show(downloadUrl, mime, true);
}

function getAbsolutePath(url: string): string {
  const urlObj = new URL(url);
  return urlObj.pathname + urlObj.search + urlObj.hash;
}

registerFileAction(new FileAction({
  id: 'view-epub',
  iconSvgInline: () => '<svg></svg>',
  displayName: () => 'View',
  enabled (nodes) {
    return nodes.filter((node) => (node.permissions & Permission.READ) !== 0).length > 0;
  },
  exec: async function (file, view, dir) {
    actionHandler(file, 'application/epub+zip', dir);
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
