@extends('telegram::layouts.mini-app')

@section('title', 'Informasi Cuaca')

@section('content')
<div class="container py-0" style="max-width:600px; margin:0 auto;">
  <div id="weather-app">
    <div id="loading-view" class="text-center py-5">
      <div class="spinner-border text-primary" role="status"></div>
      <p class="mt-2 text-muted">
        Memuat data cuaca...
      </p>
    </div>
    <div id="weather-view" style="display:none;"></div>
    <div id="settings-view" style="display:none;"></div>
  </div>
</div>
@endsection

@push('styles')
<style>
  body {
    background-color: var(--tg-theme-bg-color) !important;
    color: var(--tg-theme-text-color) !important;
    padding: 0 !important;
    margin: 0 !important;
  }
  .container {
    padding-left: 0 !important;
    padding-right: 0 !important;
  }
  .card {
    background-color: var(--tg-theme-secondary-bg-color) !important;
    border-color: var(--tg-theme-section-separator-color) !important;
    border-radius: 0;
  }
  .card-header {
    background-color: var(--tg-theme-button-color) !important;
    color: var(--tg-theme-button-text-color) !important;
    border-radius: 0;
  }
  .form-control, .input-group-text {
    background-color: var(--tg-theme-bg-color) !important;
    color: var(--tg-theme-text-color) !important;
    border-color: var(--tg-theme-section-separator-color) !important;
  }
  .btn-primary {
    background-color: var(--tg-theme-button-color) !important;
    border-color: var(--tg-theme-button-color) !important;
    color: var(--tg-theme-button-text-color) !important;
  }
  .btn-outline-secondary {
    border-color: var(--tg-theme-section-separator-color) !important;
    color: var(--tg-theme-hint-color) !important;
  }
  .text-muted {
    color: var(--tg-theme-hint-color) !important;
  }
  .weather-icon img {
    width: 80px;
    height: 80px;
  }
  .temperature {
    font-size: 3rem;
    font-weight: 300;
  }
  .detail-item {
    background-color: var(--tg-theme-secondary-bg-color);
    border-radius: 12px;
    padding: 10px;
    text-align: center;
  }
  .detail-item i {
    font-size: 1.5rem;
    color: var(--tg-theme-button-color);
  }
  .forecast-hour-card {
    background-color: var(--tg-theme-secondary-bg-color);
    border-radius: 12px;
    padding: 8px;
    text-align: center;
    min-width: 80px;
    cursor: pointer;
    transition: transform 0.2s;
  }
  .forecast-hour-card:active {
    transform: scale(0.96);
  }
  .pagination-wrapper {
    overflow-x: auto;
    text-align: center;
    margin: 1rem 0;
  }
  .pagination {
    display: inline-flex;
    flex-wrap: nowrap;
    gap: 0.25rem;
  }
  .page-item {
    flex-shrink: 0;
  }
  .error-container {
    background-color: var(--tg-theme-secondary-bg-color);
    border-left: 4px solid #dc3545;
    padding: 16px;
    margin: 16px;
    border-radius: 12px;
  }
  .error-container .error-message {
    color: #dc3545;
    font-weight: 500;
    margin-bottom: 8px;
  }
  .error-container .error-detail {
    font-size: 0.8rem;
    color: var(--tg-theme-hint-color);
    word-break: break-word;
  }
</style>
@endpush

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
  (function() {
  // ======================== FALLBACK TELEGRAM APP ========================
  const { fetchWithAuth, showToast, showLoading, hideLoading, escapeHtml } = window.TelegramApp;

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
  const directions = ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];
  const idx = Math.round(deg / 45) % 8;
  return directions[idx];
  }

  async function loadWeather(lat = null, lon = null, city = null) {
  try {
  showLoading('Memuat data cuaca...');
  const body = {};
  if (city) body.city = city;
  else if (lat && lon) { body.latitude = lat; body.longitude = lon; }
  else throw new Error('Tidak ada lokasi yang diberikan');

  const current = await fetchWithAuth('{{ config("app.url") }}/api/weather/current', { method: 'POST', body: JSON.stringify(body) });
  if (!current.success) throw new Error(current.message || 'Gagal memuat cuaca');
  weatherData = current.data;

  if (weatherData.location.latitude && weatherData.location.longitude) {
  try {
  const aqiRes = await fetchWithAuth('{{ config("app.url") }}/api/weather/air-quality', { method: 'POST', body: JSON.stringify({ latitude: weatherData.location.latitude, longitude: weatherData.location.longitude }) });
  if (aqiRes.success) aqiData = aqiRes.data;
  else aqiData = null;
  } catch(e) { aqiData = null; console.warn('AQI fetch error:', e); }
  try {
  const uvRes = await fetchWithAuth('{{ config("app.url") }}/api/weather/uv-index', { method: 'POST', body: JSON.stringify({ latitude: weatherData.location.latitude, longitude: weatherData.location.longitude }) });
  if (uvRes.success) uvData = uvRes.data;
  else uvData = null;
  } catch(e) { uvData = null; console.warn('UV fetch error:', e); }
  }

  const forecastRes = await fetchWithAuth('{{ config("app.url") }}/api/weather/hourly-forecast', { method: 'POST', body: JSON.stringify({ ...body, timezone_offset: weatherData.location.timezone_offset || 0 }) });
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
  const res = await fetchWithAuth('{{ config("app.url") }}/api/weather/settings');
  settingsData = res.data || {};
  } catch(e) {
  console.warn('loadSettings error:', e);
  settingsData = {};
  }
  }

  async function saveSettings(formData) {
  try {
  showLoading('Menyimpan...');
  const res = await fetchWithAuth('{{ config("app.url") }}/api/weather/settings', { method: 'POST', body: JSON.stringify(formData) });
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
  <div class="col-4"><div class="detail-item"><i class="bi bi-wind"></i><div class="value">${(w.current.wind_speed * 3.6).toFixed(1)} km/j</div><div class="label">${getWindDirection(w.current.wind_deg)}</div></div></div>
  <div class="col-4"><div class="detail-item"><i class="bi bi-cloud"></i><div class="value">${w.current.clouds}%</div><div class="label">Awan</div></div></div>
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
  const popIcon = pop >= 70 ? 'bi-droplet-fill' : (pop >= 30 ? 'bi-droplet-half' : 'bi-droplet');
  html += `<div class="forecast-hour-card" data-index="${idx}">
  <div class="forecast-hour-time">${item.time}</div>
  <img src="https://openweathermap.org/img/wn/${item.icon}.png" width="40" height="40">
  <div class="forecast-hour-temp">${item.temp}°C</div>
  <div class="small text-muted">${item.description?.substring(0,3)}</div>
  ${pop > 0 ? `<div class="small"><i class="bi ${popIcon}"></i>${pop}%</div>` : ''}
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
  if (forecastData && forecastData.chart && forecastData.chart.labels) drawChart(forecastData.chart);
  } catch (err) {
  handleGlobalError(err, 'renderWeatherView');
  }
  }

  function drawChart(chartData) {
  try {
  const canvas = document.getElementById('tempChart');
  if (!canvas) return;
  if (window.tempChart) window.tempChart.destroy();
  window.tempChart = new Chart(canvas, {
  type: 'line',
  data: { labels: chartData.labels, datasets: [{ label: 'Suhu (°C)', data: chartData.temps, borderColor: '#f1c40f', backgroundColor: 'rgba(241,196,15,0.1)', tension: 0.3, fill: true }] },
  options: { responsive: true, maintainAspectRatio: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: false } } }
  });
  } catch (err) {
  console.error('drawChart error:', err);
  }
  }

  function showForecastDetail(index) {
  try {
  const item = forecastData?.hourly?.[index];
  if (!item) throw new Error('Data forecast tidak ditemukan');
  const details = item.details || item;
  const pop = details.pop || 0;
  const windSpeed = details.wind_speed ? (details.wind_speed * 3.6).toFixed(1) : '-';
  const windDeg = details.wind_deg;
  const windDir = getWindDirection(windDeg);
  const html = `
  <div class="text-center mb-3">
  <div>${details.date || ''} ${details.time || ''}</div>
  <img src="https://openweathermap.org/img/wn/${details.icon}@2x.png" width="80">
  <h4>${details.temp}°C</h4>
  <p class="text-muted">${details.description}</p>
  </div>
  <div class="row g-2">
  <div class="col-6"><div class="detail-item"><i class="bi bi-thermometer-half"></i><div class="value">${details.feels_like ?? '-'}°C</div><div class="label">Terasa</div></div></div>
  <div class="col-6"><div class="detail-item"><i class="bi bi-droplet"></i><div class="value">${details.humidity ?? '-'}%</div><div class="label">Kelembaban</div></div></div>
  <div class="col-6"><div class="detail-item"><i class="bi bi-droplet-half"></i><div class="value">${pop}%</div><div class="label">Peluang Hujan</div></div></div>
  <div class="col-6"><div class="detail-item"><i class="bi bi-wind"></i><div class="value">${windSpeed} km/j ${windDeg ? `<span style="display:inline-block;transform:rotate(${windDeg}deg)"><i class="bi bi-arrow-up-short"></i></span>` : ''}</div><div class="label">Angin (${windDir})</div></div></div>
  </div>
  `;
  let modal = document.getElementById('globalModal');
  if (!modal) {
  modal = document.createElement('div');
  modal.id = 'globalModal';
  modal.style.cssText = 'position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:var(--tg-theme-bg-color);color:var(--tg-theme-text-color);border-radius:20px;padding:20px;max-width:300px;width:90%;z-index:10001;box-shadow:0 4px 20px rgba(0,0,0,0.2);';
  modal.innerHTML = `<div id="globalModalContent"></div><button class="btn btn-sm btn-secondary mt-3" style="width:100%" onclick="this.parentElement.style.display='none'">Tutup</button>`;
  document.body.appendChild(modal);
  }
  document.getElementById('globalModalContent').innerHTML = html;
  modal.style.display = 'block';
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
  <input class="form-check-input" type="checkbox" id="notifications_enabled" ${notifications ? 'checked' : ''}>
  <label class="form-check-label">Aktifkan notifikasi cuaca harian</label>
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
  latitude: document.getElementById('latitude').value ? parseFloat(document.getElementById('latitude').value) : undefined,
  longitude: document.getElementById('longitude').value ? parseFloat(document.getElementById('longitude').value) : undefined,
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
  const settings = await fetchWithAuth('{{ config("app.url") }}/api/weather/settings');
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

  function requestLiveLocation() {
  showLoading('Meminta lokasi...');
  const tg = window.Telegram?.WebApp;
  if (tg && tg.LocationManager && typeof tg.LocationManager.getLocation === 'function') {
  tg.LocationManager.getLocation((location) => {
  if (location) loadWeather(location.latitude, location.longitude);
  else browserGeolocation();
  });
  } else {
  browserGeolocation();
  }
  function browserGeolocation() {
  if (navigator.geolocation) {
  navigator.geolocation.getCurrentPosition(
  (pos) => loadWeather(pos.coords.latitude, pos.coords.longitude),
  () => { showToast('Gagal mendapatkan lokasi, silakan atur lokasi default di pengaturan.'); showSettingsView(); }
  );
  } else {
  showToast('Geolocation tidak didukung, silakan atur lokasi manual.');
  showSettingsView();
  }
  }
  }

  function requestCurrentLocation() {
  const statusSpan = document.getElementById('locationStatus');
  if (statusSpan) statusSpan.innerText = 'Meminta lokasi...';
  const tg = window.Telegram?.WebApp;
  if (tg && tg.LocationManager && typeof tg.LocationManager.getLocation === 'function') {
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
  } else {
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
  () => { if (statusSpan) statusSpan.innerText = 'Gagal mendapatkan lokasi.'; }
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
</script>
@endpush