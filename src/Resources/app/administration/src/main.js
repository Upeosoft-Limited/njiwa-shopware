/**
 * The administration side of this plugin.
 *
 * There is one screen and it does one thing: the two checks that tell a
 * merchant whether the key they typed into the settings form actually works,
 * before a customer is the one who finds out it does not. Everything a
 * merchant configures still lives in the ordinary plugin settings form, which
 * Shopware renders from config.xml.
 */

import enGB from './snippet/en-GB.json';

import './service/njiwa.api.service';
import './module/upeo-njiwa';

Shopware.Locale.extend('en-GB', enGB);
