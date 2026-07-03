import { generateUrl } from '@nextcloud/router';

const settingsMessageSelector = '#reader-personal .msg';

function checkboxValue(id: string): string {
  const element = document.getElementById(id);
  return element instanceof HTMLInputElement && element.checked ? 'true' : 'false';
}

window.addEventListener('DOMContentLoaded', function () {
  const readerSettings = {
    save: async function () {
      const data = new URLSearchParams({
        EpubEnable: checkboxValue('EpubEnable'),
        PdfEnable: checkboxValue('PdfEnable'),
        CbxEnable: checkboxValue('CbxEnable'),
      });

      OC.msg.startSaving(settingsMessageSelector);
      const response = await fetch(generateUrl('apps/epubviewer/settings/set'), {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
          requesttoken: OC.requestToken,
        },
        body: data,
      });
      readerSettings.afterSave(await response.json());
    },
    afterSave: function (data) {
      OC.msg.finishedSaving(settingsMessageSelector, data);
    },
  };

  document.getElementById('EpubEnable')?.addEventListener('change', readerSettings.save);
  document.getElementById('PdfEnable')?.addEventListener('change', readerSettings.save);
  document.getElementById('CbxEnable')?.addEventListener('change', readerSettings.save);
});
