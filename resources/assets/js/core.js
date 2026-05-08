// core.js
(function(window, document, undefined) {
  'use strict';

  // ======================== DEPENDENSI DARI LAYOUT ========================
  // diasumsikan window.TelegramApp dan BASE_URL sudah ada
  const tgApp = window.TelegramApp;
  if (!tgApp) {
    console.error('TelegramApp tidak tersedia');
    return;
  }

  const {
    fetchWithAuth,
    showToast,
    showLoading,
    hideLoading,
    escapeHtml
  } = tgApp;

  // ======================== STATE PRIVATE ========================
  let _state = {
    weather: null,
    // data cuaca terkini
    forecast: null,
    // data forecast 24 jam
    aqi: null,
    // data kualitas udara
    uv: null,
    // data indeks UV
    settings: null,
    // pengaturan dari backend
    loading: true,
    // status loading global
    error: null,
    // pesan error
    currentView: 'weather' // 'weather' atau 'settings'
  };

  const _listeners = [];

  // ======================== PUBLIC API CORE ========================
  const Core = {};

  // --- State management ---
  Core.getState = function() {
    return _state;
  };

  Core.setState = function(newState) {
    Object.assign(_state, newState);
    _listeners.forEach(function(fn) {
      try {
        fn(_state);
      } catch(e) {
        console.error(e);
      }
    });
  };

  Core.subscribe = function(fn) {
    if (typeof fn === 'function') _listeners.push(fn);
    return function unsubscribe() {
      var idx = _listeners.indexOf(fn);
      if (idx !== -1) _listeners.splice(idx, 1);
    };
  };

  // --- API wrapper (menggunakan fetchWithAuth) ---
  function _apiRequest(endpoint, method, body) {
    var options = {
      method: method
    };
    if (body) {
      options.body = JSON.stringify(body);
      options.headers = {
        'Content-Type': 'application/json'
      };
    }
    return fetchWithAuth(BASE_URL + endpoint, options);
  }

  Core.api = {
    get: function(endpoint) {
      return _apiRequest(endpoint, 'GET');
    },
    post: function(endpoint, body) {
      return _apiRequest(endpoint, 'POST', body);
    },
    put: function(endpoint, body) {
      return _apiRequest(endpoint, 'PUT', body);
    },
    del: function(endpoint) {
      return _apiRequest(endpoint, 'DELETE');
    }
  };

  Core.getWindDirection = function(deg) {
    if (deg === undefined || deg === null) return '';
    var directions = ['N',
      'NE',
      'E',
      'SE',
      'S',
      'SW',
      'W',
      'NW'];
    var idx = Math.round(deg / 45) % 8;
    return directions[idx];
  };

  // --- Helper yang ekspos ke UI (agar tidak perlu ambil dari TelegramApp) ---
  Core.showToast = showToast;
  Core.showLoading = showLoading;
  Core.hideLoading = hideLoading;
  Core.escapeHtml = escapeHtml;

  // ======================== EXPOSE GLOBAL ========================
  window.WeatherAppCore = Core;
})(window, document);