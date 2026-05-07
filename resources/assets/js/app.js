(function() {
  // ======================== FALLBACK TELEGRAM APP ========================
  const {
    fetchWithAuth, showToast, showLoading, hideLoading, escapeHtml
  } = window.TelegramApp;

  // ======================== STATE ========================
  let weatherData = null;
  let forecastData = null;
  let aqiData = null;
  let uvData = null;
  let settingsData = null;
  let currentView = 'weather'; // 'weather' or 'settings'

  // Helper: log error ke console dan tampilkan di UI
  function handleGlobalError(error, context = 'Umum') {
    console.error(`[${context}] Error:`, error);
    alert(error.message);
    const errorMsg = error.message || String(error);
    showToast(`Terjadi kesalahan: ${errorMsg}`);
    // Tampilkan error di area konten jika sedang tidak loading
    const weatherDiv = document.getElementById('weather-view');
    const settingsDiv = document.getElementById('settings-view');
    const loadingDiv = document.getElementById('loading-view');
    if (weatherDiv && settingsDiv) {
      const errorHtml = `
      <div class="error-container">
      <div class="error-message"><i class="bi bi-exclamation-triangle-fill me-2"></i>Gagal memuat data</div>
      <div class="error-detail">${escapeHtml(errorMsg)}</div>
      <button class="btn btn-primary btn-sm mt-3" onclick="location.reload()">Muat Ulang Halaman</button>
      </div>
      `;
      weatherDiv.innerHTML = errorHtml;
      settingsDiv.innerHTML = errorHtml;
      weatherDiv.style.display = 'block';
      settingsDiv.style.display = 'none';
      loadingDiv.style.display = 'none';
    }
  }

  // ======================== FUNGSI UTAMA ========================
  function getWindDirection(deg) {
    if (deg === undefined || deg === null) return '';
    const directions = ['N',
      'NE',
      'E',
      'SE',
      'S',
      'SW',
      'W',
      'NW'];
    const idx = Math.round(deg / 45) % 8;
    return directions[idx];
  }

  async function loadWeather(lat = null, lon = null, city = null) {
    try {
      showLoading('Memuat data cuaca...');
      const body = {};
      if (city) body.city = city;
      else if (lat && lon) {
        body.latitude = lat; body.longitude = lon;
      } else throw new Error('Tidak ada lokasi yang diberikan');

      const current = await fetchWithAuth(BASE_URL + '/api/weather/current', {
        method: 'POST', body: JSON.stringify(body)
      });
      if (!current.success) throw new Error(current.message || 'Gagal memuat cuaca');
      weatherData = current.data;

      if (weatherData.location.latitude && weatherData.location.longitude) {
        try {
          const aqiRes = await fetchWithAuth(BASE_URL + '/api/weather/air-quality', {
            method: 'POST', body: JSON.stringify({
              latitude: weatherData.location.latitude, longitude: weatherData.location.longitude
            })
          });
          if (aqiRes.success) aqiData = aqiRes.data;
          else aqiData = null;
        } catch(e) {
          aqiData = null;
          console.warn('AQI fetch error:', e);
        }
        try {
          const uvRes = await fetchWithAuth(BASE_URL + '/api/weather/uv-index', {
            method: 'POST', body: JSON.stringify({
              latitude: weatherData.location.latitude, longitude: weatherData.location.longitude
            })
          });
          if (uvRes.success) uvData = uvRes.data;
          else uvData = null;
        } catch(e) {
          uvData = null; console.warn('UV fetch error:', e);
        }
      }

      const forecastRes = await fetchWithAuth(BASE_URL + '/api/weather/hourly-forecast', {
        method: 'POST', body: JSON.stringify({
          ...body, timezone_offset: weatherData.location.timezone_offset || 0
        })
      });
      if (forecastRes.success) forecastData = forecastRes.data;
      else forecastData = null;

      renderWeatherView();
    } catch (err) {
      handleGlobalError(err, 'loadWeather');
    } finally {
      hideLoading();
    }
  }

  async function loadSettings() {
    try {
      const res = await fetchWithAuth(BASE_URL + '/api/weather/settings');
      settingsData = res.data || {};
    } catch(e) {
      console.warn('loadSettings error:', e);
      settingsData = {};
    }
  }

  async function saveSettings(formData) {
    try {
      showLoading('Menyimpan...');
      const res = await fetchWithAuth(BASE_URL + '/api/weather/settings', {
        method: 'POST', body: JSON.stringify(formData)
      });
      if (res.success) {
        showToast('Pengaturan disimpan');
        await loadSettings();
        showWeatherView();
      } else {
        throw new Error(res.message || 'Gagal menyimpan');
      }
    } catch (err) {
      handleGlobalError(err, 'saveSettings');
    } finally {
      hideLoading();
    }
  }

  function renderWeatherView() {
    try {
      if (!weatherData) throw new Error('Data cuaca tidak tersedia');
      const w = weatherData;
      const iconUrl = `https://openweathermap.org/img/wn/${w.weather.icon}@2x.png`;
      // Data tekanan dan jarak pandang
      const visibilityKm = w.current.visibility ? (w.current.visibility / 1000).toFixed(1): '-';
      const pressureVal = w.current.pressure ? `${w.current.pressure} mBar`: '-';
      const windDeg = w.current.wind_deg;
      const windDir = getWindDirection(windDeg);
      let html = `
      <div class="card shadow">
      <div class="card-header d-flex justify-content-between align-items-center">
      <h4 class="mb-0"><i class="bi bi-cloud-sun me-2"></i>Informasi Cuaca</h4>
      <div>
      <button id="settingsBtn" class="btn btn-sm btn-outline-light"><i class="bi bi-gear-fill"></i></button>
      <button id="refreshWeatherBtn" class="btn btn-sm btn-outline-light ms-2"><i class="bi bi-arrow-repeat"></i></button>
      </div>
      </div>
      <div class="card-body">
      <div class="text-center mb-4">
      <h5>${escapeHtml(w.location.name)}, ${escapeHtml(w.location.country)}</h5>
      <div class="weather-icon my-2"><img src="${iconUrl}" alt="${w.weather.description}"></div>
      <div class="temperature">${w.current.temperature}°C</div>
      <div class="text-muted text-uppercase">${escapeHtml(w.weather.description)}</div>
      <div class="mt-1">Terasa ${w.current.feels_like}°C</div>
      </div>
      <div class="row g-2 mb-3">
      <div class="col-4"><div class="detail-item"><i class="bi bi-droplet"></i><div class="value">${w.current.humidity}%</div><div class="label">Kelembaban</div></div></div>
      <div class="col-4"><div class="detail-item"><i class="bi bi-wind"></i><div class="value">${(w.current.wind_speed * 3.6).toFixed(1)} km/j ${windDeg ? `<span style="display:inline-block;transform:rotate(${windDeg}deg)"><i class="bi bi-arrow-up-short"></i></span>`: ''}</div><div class="label">Angin (${windDir})</div></div></div>
      <div class="col-4"><div class="detail-item"><i class="bi bi-cloud"></i><div class="value">${w.current.clouds}%</div><div class="label">Awan</div></div></div>
      </div>
      <div class="row g-2 mb-3">
      <div class="col-6"><div class="detail-item"><i class="bi bi-speedometer2"></i><div class="value">${pressureVal}</div><div class="label">Tekanan</div></div></div>
      <div class="col-6"><div class="detail-item"><i class="bi bi-eye"></i><div class="value">${visibilityKm} km</div><div class="label">Jarak Pandang</div></div></div>
      </div>
      <div class="row g-2 mb-3">
      <div class="col-6"><div class="detail-item"><i class="bi bi-sunrise"></i><div class="value">${w.sun.rise}</div><div class="label">Terbit</div></div></div>
      <div class="col-6"><div class="detail-item"><i class="bi bi-sunset"></i><div class="value">${w.sun.set}</div><div class="label">Terbenam</div></div></div>
      </div>
      `;
      if (aqiData) {
        html += `<hr><div class="mb-3"><h6><i class="bi bi-activity me-2"></i>Kualitas Udara (AQI)</h6><div class="detail-item"><div class="value">${aqiData.level}</div><div class="label">Indeks: ${aqiData.aqi}</div><div class="small text-muted mt-1">${aqiData.recommendation}</div></div></div>`;
      }
      if (uvData) {
        html += `<div class="mb-3"><h6><i class="bi bi-brightness-high me-2"></i>Indeks UV</h6><div class="detail-item"><div class="value" style="color: ${uvData.color};">${uvData.uvi} - ${uvData.level}</div><div class="small text-muted">${uvData.recommendation}</div></div></div>`;
      }
      if (forecastData && forecastData.hourly && forecastData.hourly.length) {
        html += `<hr><h6 class="mt-3 mb-3"><i class="bi bi-clock-history me-2"></i>Perkiraan 24 Jam</h6><div class="d-flex flex-nowrap overflow-auto gap-2 pb-2">`;
        forecastData.hourly.forEach((item, idx) => {
          const pop = item.pop || 0;
          const popIcon = pop >= 70 ? 'bi-droplet-fill': (pop >= 30 ? 'bi-droplet-half': 'bi-droplet');
          html += `<div class="forecast-hour-card" data-index="${idx}">
          <div class="forecast-hour-time">${item.time}</div>
          <img src="https://openweathermap.org/img/wn/${item.icon}.png" width="40" height="40">
          <div class="forecast-hour-temp">${item.temp}°C</div>
          <div class="small text-muted">${item.description?.substring(0, 3)}</div>
          ${pop > 0 ? `<div class="small"><i class="bi ${popIcon}"></i>${pop.toFixed(2)}%</div>`: ''}
          </div>`;
        });
        html += `</div>`;
        if (forecastData.chart && forecastData.chart.labels) {
          html += `<div class="mt-3"><canvas id="tempChart" height="150"></canvas></div>`;
        }
      } else {
        html += `<div class="alert alert-warning mt-3">Data forecast tidak tersedia.</div>`;
      }
      html += `<div class="text-muted small text-center mt-3"><i class="bi bi-clock me-1"></i>Diperbarui: ${new Date(w.updated_at).toLocaleTimeString()}</div></div></div>`;
      document.getElementById('weather-view').innerHTML = html;
      document.getElementById('weather-view').style.display = 'block';
      document.getElementById('settings-view').style.display = 'none';
      document.getElementById('loading-view').style.display = 'none';

      // Attach event listeners
      document.getElementById('settingsBtn').addEventListener('click', () => showSettingsView());
      document.getElementById('refreshWeatherBtn').addEventListener('click', () => {
        const loc = weatherData.location;
        if (loc.latitude && loc.longitude) loadWeather(loc.latitude, loc.longitude);
        else if (loc.city) loadWeather(null, null, loc.city);
        else loadWeather(null, null, loc.name);
      });
      document.querySelectorAll('.forecast-hour-card').forEach(card => {
        card.addEventListener('click', () => {
          const idx = parseInt(card.dataset.index);
          showForecastDetail(idx);
        });
      });
      if (forecastData && forecastData.chart && forecastData.chart.labels) setTimeout(() => drawChart(forecastData.chart), 50);
    } catch (err) {
      handleGlobalError(err, 'renderWeatherView');
    }
  }

  function drawChart(chartData) {
    try {
      const canvas = document.getElementById('tempChart');
      if (!canvas) {
        console.warn('Canvas not found, retrying...');
        setTimeout(() => drawChart(chartData), 100);
        return;
      }
      // Hancurkan chart sebelumnya hanya jika ada dan memiliki method destroy
      if (window.tempChart && typeof window.tempChart.destroy === 'function') {
        window.tempChart.destroy();
      }
      // Pastikan Chart constructor tersedia
      if (typeof Chart === 'undefined') {
        console.warn('Chart.js not loaded yet, retrying...');
        setTimeout(() => drawChart(chartData), 200);
        return;
      }
      const buttonColor = getComputedStyle(document.documentElement).getPropertyValue('--tg-theme-button-color').trim() || '#007aff';
      window.tempChart = new Chart(canvas, {
        type: 'line',
        data: {
          labels: chartData.labels,
          datasets: [{
            label: 'Suhu (°C)',
            data: chartData.temps,
            borderColor: buttonColor,
            backgroundColor: 'rgba(0,122,255,0.1)',
            tension: 0.3,
            fill: true,
            pointBackgroundColor: buttonColor,
            pointBorderColor: '#fff',
            pointRadius: 3
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: true,
          plugins: {
            legend: {
              display: false
            },
            tooltip: {
              callbacks: {
                label: (ctx) => `${ctx.raw}°C`
              }
            }
          },
          scales: {
            y: {
              beginAtZero: false, title: {
                display: true, text: 'Suhu (°C)', color: 'var(--tg-theme-hint-color)'
              }
            },
            x: {
              title: {
                display: true, text: 'Waktu', color: 'var(--tg-theme-hint-color)'
              }, ticks: {
                autoSkip: true, maxTicksLimit: 6
              }
            }
          }
        }
      });
    } catch (err) {
      console.error('drawChart error:', err);
      alert(err.message);
    }
  }

  function showForecastDetail(index) {
    try {
      const item = forecastData?.hourly?.[index];
      if (!item) throw new Error('Data forecast tidak ditemukan');
      const details = item.details || item;
      const pop = details.pop || 0;
      const windSpeed = details.wind_speed ? (details.wind_speed * 3.6).toFixed(1): '-';
      const windDeg = details.wind_deg;
      const windDir = getWindDirection(windDeg);
      const pressure = details.pressure ? `${details.pressure} mBar`: '-';
      const visibility = details.visibility ? (details.visibility / 1000).toFixed(1) + ' km': '-';
      const uv = details.uvi !== undefined ? details.uvi: '-';
      const clouds = details.clouds !== undefined ? `${details.clouds}%`: '-';

      const html = `
      <div class="text-center mb-3">
      <div class="text-muted small">${details.date || ''} ${details.time || ''}</div>
      <img src="https://openweathermap.org/img/wn/${details.icon}@4x.png" width="80" height="80">
      <h4>${details.temp}°C</h4>
      <p class="text-muted">${details.description}</p>
      </div>
      <div class="row g-2">
      <div class="col-6"><div class="detail-item"><i class="bi bi-thermometer-half"></i><div class="value">${details.feels_like || '-'}°C</div><div class="label">Terasa</div></div></div>
      <div class="col-6"><div class="detail-item"><i class="bi bi-droplet"></i><div class="value">${details.humidity || '-'}%</div><div class="label">Kelembaban</div></div></div>
      <div class="col-6"><div class="detail-item"><i class="bi bi-droplet-half"></i><div class="value">${pop}%</div><div class="label">Peluang Hujan</div></div></div>
      <div class="col-6"><div class="detail-item"><i class="bi bi-wind"></i><div class="value">${windSpeed} km/j ${windDeg ? `<span style="display:inline-block;transform:rotate(${windDeg}deg)"><i class="bi bi-arrow-up-short"></i></span>`: ''}</div><div class="label">Angin (${windDir})</div></div></div>
      <div class="col-6"><div class="detail-item"><i class="bi bi-speedometer2"></i><div class="value">${pressure}</div><div class="label">Tekanan</div></div></div>
      <div class="col-6"><div class="detail-item"><i class="bi bi-eye"></i><div class="value">${visibility}</div><div class="label">Jarak Pandang</div></div></div>
      <div class="col-6"><div class="detail-item"><i class="bi bi-brightness-high"></i><div class="value">${uv}</div><div class="label">Indeks UV</div></div></div>
      <div class="col-6"><div class="detail-item"><i class="bi bi-cloud"></i><div class="value">${clouds}</div><div class="label">Awan</div></div></div>
      </div>
      `;

      const modalBody = document.getElementById('forecastModalBody');
      if (modalBody) modalBody.innerHTML = html;
      const modalEl = document.getElementById('forecastModal');
      if (modalEl) {
        const modal = new bootstrap.Modal(modalEl);
        modal.show();
      } else {
        console.error('Modal element not found');
        alert("Modal element not found.");
      }
    } catch (err) {
      handleGlobalError(err, 'showForecastDetail');
    }
  }

  async function showSettingsView() {
    try {
      if (!settingsData) await loadSettings();
      const city = settingsData.city || '';
      const lat = settingsData.latitude || '';
      const lon = settingsData.longitude || '';
      const notifications = settingsData.notifications_enabled || false;
      let html = `
      <div class="card shadow">
      <div class="card-header d-flex justify-content-between align-items-center">
      <h4 class="mb-0"><i class="bi bi-gear-fill me-2"></i>Pengaturan Cuaca</h4>
      <button id="backToWeatherBtn" class="btn btn-sm btn-outline-light"><i class="bi bi-arrow-left"></i> Kembali</button>
      </div>
      <div class="card-body">
      <form id="settingsForm">
      <h5>Lokasi Default</h5>
      <p class="text-muted small">Kosongkan untuk meminta lokasi setiap kali.</p>
      <div class="mb-3">
      <label class="form-label">Nama Kota</label>
      <input type="text" class="form-control" id="city" name="city" value="${escapeHtml(city)}" placeholder="Contoh: Jakarta">
      </div>
      <div class="row">
      <div class="col-md-6 mb-3">
      <label class="form-label">Latitude</label>
      <input type="number" step="any" class="form-control" id="latitude" value="${escapeHtml(lat)}" placeholder="-6.2088">
      </div>
      <div class="col-md-6 mb-3">
      <label class="form-label">Longitude</label>
      <input type="number" step="any" class="form-control" id="longitude" value="${escapeHtml(lon)}" placeholder="106.8456">
      </div>
      </div>
      <div class="mb-3">
      <button type="button" class="btn btn-outline-primary" id="autoLocationBtn"><i class="bi bi-geo-alt me-2"></i>Ambil lokasi saat ini</button>
      <span class="text-muted ms-2" id="locationStatus"></span>
      </div>
      <hr>
      <div class="form-check form-switch mb-3">
      <input class="form-check-input" type="checkbox" id="notifications_enabled" ${notifications ? 'checked': ''} value="1">
      <label class="form-check-label" for="notifications_enabled">Aktifkan notifikasi cuaca harian</label>
      </div>
      <button type="submit" class="btn btn-primary w-100">Simpan Pengaturan</button>
      </form>
      </div>
      </div>
      `;
      document.getElementById('settings-view').innerHTML = html;
      document.getElementById('weather-view').style.display = 'none';
      document.getElementById('settings-view').style.display = 'block';
      document.getElementById('loading-view').style.display = 'none';
      document.getElementById('backToWeatherBtn').addEventListener('click', () => showWeatherView());
      document.getElementById('autoLocationBtn').addEventListener('click', requestCurrentLocation);
      document.getElementById('settingsForm').addEventListener('submit', (e) => {
        e.preventDefault();
        const formData = {
          city: document.getElementById('city').value || undefined,
          latitude: document.getElementById('latitude').value ? parseFloat(document.getElementById('latitude').value): undefined,
          longitude: document.getElementById('longitude').value ? parseFloat(document.getElementById('longitude').value): undefined,
          notifications_enabled: document.getElementById('notifications_enabled').checked
        };
        saveSettings(formData);
      });
    } catch (err) {
      handleGlobalError(err, 'showSettingsView');
    }
  }

  function showWeatherView() {
    if (weatherData) renderWeatherView();
    else loadDefaultLocation();
  }

  async function loadDefaultLocation() {
    try {
      showLoading('Mengambil lokasi default...');
      const settings = await fetchWithAuth(BASE_URL + '/api/weather/settings');
      settingsData = settings.data || {};
      if (settingsData.city) {
        await loadWeather(null, null, settingsData.city);
      } else if (settingsData.latitude && settingsData.longitude) {
        await loadWeather(settingsData.latitude, settingsData.longitude);
      } else {
        requestLiveLocation();
      }
    } catch(e) {
      console.warn('loadDefaultLocation fallback:', e);
      requestLiveLocation();
    } finally {
      hideLoading();
    }
  }

  async function requestLiveLocation() {
    showLoading('Meminta lokasi...');
    const tg = window.Telegram?.WebApp;

    // Jika tidak ada Telegram WebApp, langsung fallback ke browser
    if (!tg || !tg.LocationManager) {
      console.warn('Telegram LocationManager not available, fallback to browser geolocation');
      browserGeolocation();
      return;
    }

    // Inisialisasi LocationManager dengan Promise
    const initLocationManager = () => {
      return new Promise((resolve) => {
        tg.LocationManager.init(() => {
          console.log('LocationManager initialized');
          resolve();
        });
      });
    };

    try {
      await initLocationManager();
      // Setelah init, request lokasi
      tg.LocationManager.getLocation((location) => {
        if (location && location.latitude && location.longitude) {
          loadWeather(location.latitude, location.longitude);
        } else {
          browserGeolocation();
        }
      });
    } catch (err) {
      console.error('LocationManager init error:',
        err);
      browserGeolocation();
    }

    function browserGeolocation() {
      if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
          (pos) => loadWeather(pos.coords.latitude, pos.coords.longitude),
          () => {
            showToast('Gagal mendapatkan lokasi, silakan atur lokasi default di pengaturan.');
            showSettingsView();
          }
        );
      } else {
        showToast('Geolocation tidak didukung, silakan atur lokasi manual.');
        showSettingsView();
      }
    }
  }

  async function requestCurrentLocation() {
    const statusSpan = document.getElementById('locationStatus');
    if (statusSpan) statusSpan.innerText = 'Meminta lokasi...';

    const tg = window.Telegram?.WebApp;
    if (tg && tg.LocationManager) {
      // Inisialisasi dulu
      const initLocationManager = () => {
        return new Promise((resolve) => {
          tg.LocationManager.init(() => {
            console.log('LocationManager initialized');
            resolve();
          });
        });
      };
      try {
        await initLocationManager();
        tg.LocationManager.getLocation((location) => {
          if (location) {
            const latInput = document.getElementById('latitude');
            const lonInput = document.getElementById('longitude');
            const cityInput = document.getElementById('city');
            if (latInput) latInput.value = location.latitude;
            if (lonInput) lonInput.value = location.longitude;
            if (cityInput) cityInput.value = '';
            if (statusSpan) statusSpan.innerText = 'Lokasi berhasil diambil.';
          } else {
            if (statusSpan) statusSpan.innerText = 'Akses ditolak.';
          }
        });
      } catch (err) {
        console.error(err);
        if (statusSpan) statusSpan.innerText = 'Gagal inisialisasi LocationManager.';
      }
    } else {
      // Fallback ke browser geolocation jika Telegram API tidak tersedia
      if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
          (pos) => {
            const latInput = document.getElementById('latitude');
            const lonInput = document.getElementById('longitude');
            const cityInput = document.getElementById('city');
            if (latInput) latInput.value = pos.coords.latitude;
            if (lonInput) lonInput.value = pos.coords.longitude;
            if (cityInput) cityInput.value = '';
            if (statusSpan) statusSpan.innerText = 'Lokasi berhasil diambil.';
          },
          () => {
            if (statusSpan) statusSpan.innerText = 'Gagal mendapatkan lokasi.';
          }
        );
      } else {
        if (statusSpan) statusSpan.innerText = 'Geolocation tidak didukung.';
      }
    }
  }

  // ======================== START ========================
  try {
    loadDefaultLocation();
  } catch (err) {
    handleGlobalError(err, 'startup');
  }
})();