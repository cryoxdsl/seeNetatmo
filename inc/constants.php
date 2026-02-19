<?php
declare(strict_types=1);

const APP_NAME_DEFAULT = 'meteo13-netatmo';
const APP_VERSION = '1.0.0';
const APP_TIMEZONE = 'Europe/Paris';
const APP_ADMIN_PATH = '/admin-meteo13';
const NETATMO_OAUTH_AUTHORIZE = 'https://api.netatmo.com/oauth2/authorize';
const NETATMO_OAUTH_TOKEN = 'https://api.netatmo.com/oauth2/token';
const NETATMO_API_STATIONS = 'https://api.netatmo.com/api/getstationsdata';
const SESSION_TIMEOUT_SECONDS = 2592000; // 30 days
const ADMIN_SESSION_TIMEOUT_SECONDS = 1800; // 30 minutes
const LOCKOUT_ATTEMPTS = 10;
const LOCKOUT_WINDOW_MINUTES = 10;
const PENDING_2FA_TTL_SECONDS = 300; // 5 minutes
const TRUSTED_2FA_COOKIE_NAME = 'meteo13_trusted_2fa';
const TRUSTED_2FA_DAYS = 30;
const DISCONNECT_THRESHOLD_MINUTES = 15;
const CRON_MAX_SECONDS = 10;
