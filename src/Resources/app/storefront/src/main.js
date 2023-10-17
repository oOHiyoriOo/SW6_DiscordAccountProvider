const PluginManager = window.PluginManager;


// Examples:
// import EmzDiscountCountdown from './scripts/emz-discount-countdown/emz-discount-countdown.plugin';
// PluginManager.register('EmzDiscountCountdown', EmzDiscountCountdown, '[emz-countdown-end]');

import DiscordOAuth from "./scripts/DiscordOauth/DiscordOauth.plugin";

PluginManager.register('DiscordOAuth', DiscordOAuth, "[discord-oauth-trigger]")