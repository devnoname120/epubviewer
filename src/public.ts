import { generateUrl } from '@nextcloud/router';
import { isPublicShare, getSharingToken } from '@nextcloud/sharing/public';

// FIXME: Hack for single public file view since it is not attached to the fileslist
window.addEventListener('DOMContentLoaded', function () {
  const mime = $('#mimetype').val();
  const supportedMimetypes = [
    'application/epub+zip',
    // 'application/pdf', // Using the official Nextcloud PDF viewer instead
    'application/x-cbr',
    'application/x-cbz',
    'application/comicbook+7z',
    'application/comicbook+ace',
    'application/comicbook+rar',
    'application/comicbook+tar',
    'application/comicbook+truecrypt',
    'application/comicbook+zip',
  ];

  if (!isPublicShare() || !supportedMimetypes.includes(mime)) {
    return;
  }

  const downloadUrl = generateUrl('/s/{token}/download', { token: getSharingToken() });

  const content = $('#files-public-content');
  const footerElmt = document.querySelector('body > footer') ?? document.querySelector('#app-content > footer');
  const mainContent = document.querySelector('#content');

  const viewerUrl = generateUrl('/apps/epubviewer/?file={downloadUrl}&type={type}', { downloadUrl, type: mime });

  // Create viewer frame
  const viewerNode = document.createElement('iframe');
  viewerNode.style.height = '100%';
  viewerNode.style.width = '100%';
  viewerNode.style.position = 'absolute';

  // Inject viewer
  content.empty();
  content.append(viewerNode);
  viewerNode.src = viewerUrl;
  footerElmt.style.display = 'none';
  mainContent.style.minHeight = 'calc(100% - var(--header-height))'; // Make the viewer take the whole height as the footer is now hidden.
  // overwrite style in order to fix the viewer on public pages
  mainContent.style.marginLeft = '0';
  mainContent.style.marginRight = '0';
  mainContent.style.width = '100%';
  mainContent.style.borderRadius = 'unset';
});
