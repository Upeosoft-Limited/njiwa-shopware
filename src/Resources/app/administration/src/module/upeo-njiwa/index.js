/**
 * One entry under Settings, in the plugins group, next to where the merchant
 * has just been editing the settings this screen checks.
 */

import './page/upeo-njiwa-check';

Shopware.Module.register('upeo-njiwa', {
    type: 'plugin',
    name: 'upeo-njiwa',
    title: 'upeo-njiwa.general.title',
    description: 'upeo-njiwa.general.description',
    color: '#25d366',
    icon: 'regular-cog',

    routes: {
        index: {
            component: 'upeo-njiwa-check',
            path: 'index',
            meta: {
                parentPath: 'sw.settings.index',
                privilege: 'system_config:read',
            },
        },
    },

    // The privilege is the one that governs the settings being checked:
    // somebody who can change the API key can find out whether it works, and
    // somebody who cannot, cannot. The endpoints enforce the same thing
    // themselves, because a hidden menu entry is not access control.
    settingsItem: [{
        group: 'plugins',
        to: 'upeo.njiwa.index',
        icon: 'regular-cog',
        name: 'upeo-njiwa',
        label: 'upeo-njiwa.general.title',
        privilege: 'system_config:read',
    }],
});
