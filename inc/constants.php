<?php
declare(strict_types=1);

const APP_DEFAULT_VERSION = '1.0.0';
const APP_TIMEZONE = 'Europe/Paris';
const NETATMO_AUTH_URL = 'https://api.netatmo.com/oauth2/authorize';
const NETATMO_TOKEN_URL = 'https://api.netatmo.com/oauth2/token';
const NETATMO_STATION_URL = 'https://api.netatmo.com/api/getstationsdata';
const DISCONNECTED_AFTER_MINUTES = 15;
const SESSION_TIMEOUT_SECONDS = 1800;
const LOCKOUT_ATTEMPTS = 10;
const LOCKOUT_MINUTES = 10;
