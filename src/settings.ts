import { loadState } from '@nextcloud/initial-state';
import { translate as t } from '@nextcloud/l10n';
import { generateUrl } from '@nextcloud/router';
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch';
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection';
import { createApp, defineComponent, h, reactive, ref } from 'vue';

import './settings.css';

const APP_ID = 'epubviewer';

type SettingKey = 'epubEnabled' | 'pdfEnabled' | 'cbxEnabled';

interface PersonalSettings {
  epubEnabled: boolean;
  pdfEnabled: boolean;
  cbxEnabled: boolean;
}

interface SaveResponse {
  status?: string;
  data?: {
    message?: string;
  };
}

const initialSettings = loadState<PersonalSettings>(APP_ID, 'personalSettings', {
  epubEnabled: true,
  pdfEnabled: false,
  cbxEnabled: true,
});

function requestToken(): string {
  const nextcloudWindow = window as Window & {
    OC?: {
      requestToken?: string;
    };
  };

  return nextcloudWindow.OC?.requestToken ?? '';
}

async function saveSettings(settings: PersonalSettings): Promise<SaveResponse> {
  const body = new URLSearchParams({
    EpubEnable: String(settings.epubEnabled),
    PdfEnable: String(settings.pdfEnabled),
    CbxEnable: String(settings.cbxEnabled),
  });
  const response = await fetch(generateUrl('/apps/{APP_ID}/settings/set', { APP_ID }), {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
      requesttoken: requestToken(),
    },
    body,
  });
  const data = (await response.json().catch(() => ({}))) as SaveResponse;

  if (!response.ok || data.status !== 'success') {
    throw new Error(data.data?.message ?? t(APP_ID, 'Could not save settings.'));
  }

  return data;
}

const PersonalSettingsApp = defineComponent({
  name: 'EpubviewerPersonalSettings',
  setup() {
    const settings = reactive<PersonalSettings>({ ...initialSettings });
    const savingKey = ref<SettingKey | null>(null);
    const message = ref('');
    const saveFailed = ref(false);

    async function updateSetting(key: SettingKey, enabled: boolean): Promise<void> {
      if (savingKey.value !== null || settings[key] === enabled) {
        return;
      }

      const previousValue = settings[key];
      settings[key] = enabled;
      savingKey.value = key;
      message.value = '';
      saveFailed.value = false;

      try {
        const data = await saveSettings({ ...settings });
        message.value = data.data?.message ?? t(APP_ID, 'Settings updated successfully.');
      } catch (error) {
        settings[key] = previousValue;
        message.value = error instanceof Error ? error.message : t(APP_ID, 'Could not save settings.');
        saveFailed.value = true;
      } finally {
        savingKey.value = null;
      }
    }

    function renderSetting(key: SettingKey, id: string, label: string) {
      return h(NcCheckboxRadioSwitch, {
        id,
        type: 'switch',
        modelValue: settings[key],
        disabled: savingKey.value !== null,
        loading: savingKey.value === key,
        'onUpdate:modelValue': (enabled: boolean) => updateSetting(key, enabled === true),
      }, {
        default: () => label,
      });
    }

    return () => h('div', { class: 'epubviewer-personal-settings' }, [
      h(NcSettingsSection, {
        name: t(APP_ID, 'Reader'),
        description: t(APP_ID, 'Select file types for which Reader should be the default viewer.'),
      }, {
        default: () => [
          h('div', { class: 'epubviewer-personal-settings__options' }, [
            renderSetting('epubEnabled', 'EpubEnable', t(APP_ID, 'Epub')),
            renderSetting('pdfEnabled', 'PdfEnable', t(APP_ID, 'PDF')),
            renderSetting('cbxEnabled', 'CbxEnable', t(APP_ID, 'CBR/CBZ')),
          ]),
          message.value
            ? h('p', {
                class: {
                  'epubviewer-personal-settings__message': true,
                  'epubviewer-personal-settings__message--error': saveFailed.value,
                },
                role: saveFailed.value ? 'alert' : undefined,
                'aria-live': 'polite',
              }, message.value)
            : null,
        ],
      }),
    ]);
  },
});

const root = document.getElementById('reader-personal');
if (root) {
  createApp(PersonalSettingsApp).mount(root);
}
