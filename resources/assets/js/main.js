// main.js - version fix
(function(window, document, undefined) {
  'use strict';

  var Core = window.WeatherAppCore;
  var UI = window.WeatherAppUI;
  if (!Core || !UI) {
    console.error('Core atau UI tidak tersedia');
    return;
  }

  // ======================== DATA FETCHING ========================
  async function fetchSettings() {
    try {
      console.log('fetchSettings: mulai');
      var res = await Core.api.get('/api/weather/settings');
      console.log('fetchSettings response:', res);
      if (res.success) {
        Core.setState({
          settings: res.data
        });
        return res.data;
      } else {
        throw new Error(res.message || 'Gagal memuat pengaturan');
      }
    } catch (err) {
      console.error('fetchSettings error:', err);
      Core.showToast('Gagal memuat pengaturan: ' + err.message, 'danger');
      Core.setState({
        settings: {}
      });
      return {};
    }
  }

  async function fetchWeatherByCity(city) {
    try {
      Core.showLoading('Mengambil cuaca untuk ' + city + '...');
      var res = await Core.api.post('/api/weather/current', {
        city: city
      });
      if (!res.success) throw new Error(res.message);
      return res.data;
    } catch (err) {
      throw err;
    } finally {
      Core.hideLoading();
    }
  }

  async function fetchWeatherByCoords(lat, lon) {
    try {
      Core.showLoading('Mengambil cuaca berdasarkan lokasi...');
      var res = await Core.api.post('/api/weather/current', {
        latitude: lat, longitude: lon
      });
      if (!res.success) throw new Error(res.message);
      return res.data;
    } catch (err) {
      throw err;
    } finally {
      Core.hideLoading();
    }
  }

  async function fetchAdditionalData(lat, lon) {
    var aqi = null,
    uv = null;
    try {
      var aqiRes = await Core.api.post('/api/weather/air-quality', {
        latitude: lat, longitude: lon
      });
      if (aqiRes.success) aqi = aqiRes.data;
    } catch(e) {
      console.warn('AQI fetch error:', e);
    }
    try {
      var uvRes = await Core.api.post('/api/weather/uv-index', {
        latitude: lat, longitude: lon
      });
      if (uvRes.success) uv = uvRes.data;
    } catch(e) {
      console.warn('UV fetch error:', e);
    }
    return {
      aqi: aqi,
      uv: uv
    };
  }

  async function fetchForecast(params) {
    try {
      var res = await Core.api.post('/api/weather/hourly-forecast', params);
      if (res.success) return res.data;
      else return null;
    } catch (err) {
      console.warn('Forecast fetch error:', err);
      return null;
    }
  }

  async function loadWeatherFromLocation(lat, lon, city) {
    try {
      Core.setState({
        loading: true, error: null
      });
      var weatherData;
      if (city) {
        weatherData = await fetchWeatherByCity(city);
      } else if (lat && lon) {
        weatherData = await fetchWeatherByCoords(lat, lon);
      } else {
        throw new Error('Tidak ada lokasi yang diberikan');
      }
      var additional = {
        aqi: null,
        uv: null
      };
      if (weatherData.location.latitude && weatherData.location.longitude) {
        additional = await fetchAdditionalData(weatherData.location.latitude, weatherData.location.longitude);
      }
      var forecastParams = {
        timezone_offset: weatherData.location.timezone_offset || 0
      };
      if (city) forecastParams.city = city;
      else if (lat && lon) {
        forecastParams.latitude = lat; forecastParams.longitude = lon;
      }
      var forecastData = await fetchForecast(forecastParams);
      Core.setState({
        weather: weatherData,
        aqi: additional.aqi,
        uv: additional.uv,
        forecast: forecastData,
        loading: false,
        error: null,
        currentView: 'weather'
      });
    } catch (err) {
      Core.setState({
        loading: false, error: err.message
      });
      Core.showToast('Gagal memuat cuaca: ' + err.message, 'danger');
    }
  }

  // ======================== GEOLOCATION (dengan timeout) ========================
  function getTelegramLocation() {
    return new Promise(function(resolve, reject) {
      var tg = window.Telegram && window.Telegram.WebApp;
      if (!tg || !tg.LocationManager) {
        reject(new Error('Telegram LocationManager tidak tersedia'));
        return;
      }
      // Inisialisasi (bisa sudah diinit, aman dipanggil ulang)
      tg.LocationManager.init(function() {
        console.log('Telegram LocationManager init done');
        var timeoutId = setTimeout(function() {
          reject(new Error('Timeout: Telegram location tidak merespon dalam 10 detik'));
        }, 10000);
        tg.LocationManager.getLocation(function(location) {
          clearTimeout(timeoutId);
          console.log('Telegram getLocation callback:', location);
          if (location && location.latitude && location.longitude) {
            resolve( {
              lat: location.latitude, lon: location.longitude
            });
          } else {
            reject(new Error('Akses lokasi ditolak atau data tidak valid'));
          }
        });
      });
    });
  }

  function getBrowserLocation() {
    return new Promise(function(resolve, reject) {
      if (!navigator.geolocation) {
        reject(new Error('Geolocation tidak didukung browser ini'));
        return;
      }
      var timeoutId = setTimeout(function() {
        reject(new Error('Browser geolocation timeout (10 detik)'));
      }, 10000);
      navigator.geolocation.getCurrentPosition(
        function(pos) {
          clearTimeout(timeoutId);
          resolve( {
            lat: pos.coords.latitude, lon: pos.coords.longitude
          });
        },
        function(err) {
          clearTimeout(timeoutId);
          reject(new Error('Browser geolocation error: ' + err.message));
        }
      );
    });
  }

  // ======================== SAVE SETTINGS ========================
  async function saveSettings(formData) {
    try {
      Core.showLoading('Menyimpan pengaturan...');
      var res = await Core.api.post('/api/weather/settings',
        formData);
      if (res.success) {
        Core.showToast('Pengaturan disimpan');
        await fetchSettings();
        var newSettings = Core.getState().settings;
        if (newSettings.city) {
          await loadWeatherFromLocation(null, null, newSettings.city);
        } else if (newSettings.latitude && newSettings.longitude) {
          await loadWeatherFromLocation(newSettings.latitude, newSettings.longitude);
        } else {
          // Jika pengaturan kosong, coba geolocation lagi
          await loadFromGeolocation(); // fungsi baru
        }
        Core.setState({
          currentView: 'weather'
        });
      } else {
        throw new Error(res.message || 'Gagal menyimpan');
      }
    } catch (err) {
      Core.showToast('Error: ' + err.message, 'danger');
    } finally {
      Core.hideLoading();
    }
  }

  // Fungsi khusus untuk mencoba geolocation dan fallback ke settings
  async function loadFromGeolocation() {
    try {
      Core.showLoading('Meminta lokasi...');
      var loc;
      try {
        loc = await getTelegramLocation();
      } catch (eTele) {
        console.warn('Telegram location gagal:', eTele);
        Core.showToast('Telegram location gagal, mencoba browser...', 'warning');
        try {
          loc = await getBrowserLocation();
        } catch (eBrowser) {
          throw new Error('Geolocation gagal: ' + eBrowser.message);
        }
      }
      await loadWeatherFromLocation(loc.lat, loc.lon);
      Core.setState({
        currentView: 'weather'
      });
    } catch (err) {
      console.error('loadFromGeolocation error:', err);
      Core.setState({
        loading: false, error: err.message
      });
      Core.showToast(err.message, 'danger');
      // Tampilkan settings view agar user bisa input manual
      Core.setState({
        currentView: 'settings'
      });
      if (!Core.getState().settings) Core.setState({
        settings: {}
      });
    } finally {
      Core.hideLoading();
    }
  }

  async function loadDefaultLocation() {
    try {
      Core.showLoading('Memuat pengaturan...');
      var settings = await fetchSettings();
      console.log('Settings after fetch:', settings);
      if (settings.city) {
        await loadWeatherFromLocation(null, null, settings.city);
      } else if (settings.latitude && settings.longitude) {
        await loadWeatherFromLocation(settings.latitude, settings.longitude);
      } else {
        // Tidak ada pengaturan lokasi, coba geolocation
        await loadFromGeolocation();
      }
    } catch (err) {
      Core.setState({
        loading: false, error: err.message
      });
      Core.showToast(err.message, 'danger');
      Core.setState({
        currentView: 'settings'
      });
    } finally {
      Core.hideLoading();
    }
  }

  // ======================== EVENT DELEGATION ========================
  function setupEventDelegation() {
    document.body.addEventListener('click', function(e) {
      var target = e.target;
      if (target.id === 'settingsBtn' || target.closest('#settingsBtn')) {
        Core.setState({
          currentView: 'settings'
        });
        UI.renderSettingsView(Core.getState());
      } else if (target.id === 'refreshWeatherBtn' || target.closest('#refreshWeatherBtn')) {
        var state = Core.getState();
        if (state.weather && state.weather.location) {
          var loc = state.weather.location;
          if (loc.latitude && loc.longitude) {
            loadWeatherFromLocation(loc.latitude, loc.longitude);
          } else if (loc.city) {
            loadWeatherFromLocation(null, null, loc.city);
          } else {
            loadWeatherFromLocation(null, null, loc.name);
          }
        } else {
          loadDefaultLocation();
        }
      } else if (target.id === 'backToWeatherBtn' || target.closest('#backToWeatherBtn')) {
        Core.setState({
          currentView: 'weather'
        });
        UI.renderWeatherView(Core.getState());
      } else if (target.closest('.forecast-hour-card')) {
        var card = target.closest('.forecast-hour-card');
        var idx = card.getAttribute('data-index');
        if (idx !== null) {
          var state = Core.getState();
          UI.showForecastDetail(parseInt(idx), state);
        }
      } else if (target.id === 'autoLocationBtn' || target.closest('#autoLocationBtn')) {
        (async function() {
          var statusSpan = document.getElementById('locationStatus');
          if (statusSpan) statusSpan.innerText = 'Meminta lokasi...';
          try {
            var loc = await getTelegramLocation();
            document.getElementById('latitude').value = loc.lat;
            document.getElementById('longitude').value = loc.lon;
            document.getElementById('city').value = '';
            if (statusSpan) statusSpan.innerText = 'Lokasi berhasil diambil.';
          } catch (err) {
            try {
              var locBrowser = await getBrowserLocation();
              document.getElementById('latitude').value = locBrowser.lat;
              document.getElementById('longitude').value = locBrowser.lon;
              document.getElementById('city').value = '';
              if (statusSpan) statusSpan.innerText = 'Lokasi berhasil diambil (browser).';
            } catch (err2) {
              if (statusSpan) statusSpan.innerText = 'Gagal mengambil lokasi.';
              Core.showToast(err2.message, 'danger');
            }
          }
        })();
      }
    });

    document.body.addEventListener('submit', function(e) {
      if (e.target && e.target.id === 'settingsForm') {
        e.preventDefault();
        var cityVal = document.getElementById('city').value;
        var latVal = document.getElementById('latitude').value;
        var lonVal = document.getElementById('longitude').value;
        var notify = document.getElementById('notifications_enabled').checked;
        var formData = {
          city: cityVal || undefined,
          latitude: latVal ? parseFloat(latVal): undefined,
          longitude: lonVal ? parseFloat(lonVal): undefined,
          notifications_enabled: notify
        };
        saveSettings(formData);
      }
    });
  }

  // ======================== SUBSCRIBE ========================
  function onStateChange(state) {
    if (state.currentView === 'weather') {
      UI.renderWeatherView(state);
    } else if (state.currentView === 'settings') {
      UI.renderSettingsView(state);
    }
  }

  // ======================== START ========================
  Core.subscribe(onStateChange);
  setupEventDelegation();
  loadDefaultLocation();
})(window, document);