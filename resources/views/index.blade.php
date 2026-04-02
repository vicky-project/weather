@extends('coreui::layouts.mini-app')
@section('title', 'Informasi Cuaca')

@section('content')
<div class="container py-3">
  <div class="row justify-content-center mb-3">
    <div class="col-md-12">
      <div class="d-flex justify-content-between align-items-center">
        <a href="{{ route('telegram.home') }}" class="btn btn-outline-secondary">
          <i class="bi bi-arrow-left me-2"></i>Kembali
        </a>
        <div>
          @if($telegramUser)
          <a href="{{ route('apps.weather.settings') }}" class="btn btn-outline-secondary me-2">
            <i class="bi bi-gear-fill"></i>
          </a>
          @endif
          <button class="btn btn-outline-secondary" id="changeLocationBtn">
            <i class="bi bi-search"></i> Ganti Lokasi
          </button>
        </div>
      </div>
    </div>
  </div>

  <div class="row justify-content-center mt-3">
    <div class="col-md-8 col-lg-6">
      <div class="card shadow">
        <div class="card-header bg-primary text-white">
          <h4 class="mb-0"><i class="bi bi-cloud-sun me-2"></i>Informasi Cuaca</h4>
        </div>
        <div class="card-body" id="weatherApp">
          {{-- Konten diisi JavaScript --}}
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Modal Detail Forecast -->
<div class="modal fade" id="forecastDetailModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="background-color: var(--tg-theme-bg-color); color: var(--tg-theme-text-color);">
      <div class="modal-header" style="border-bottom-color: var(--tg-theme-hint-color);">
        <h5 class="modal-title">Detail Cuaca</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="forecastDetailBody">
        {{-- Detail akan diisi --}}
      </div>
    </div>
  </div>
</div>
@endsection

@push('styles')
<style>
  /* (sama seperti sebelumnya) */
  body {
    background-color: var(--tg-theme-bg-color);
    color: var(--tg-theme-text-color);
  }
  .card {
    background-color: var(--tg-theme-secondary-bg-color);
    border: none;
  }
  .card-header {
    background-color: var(--tg-theme-button-color);
    color: var(--tg-theme-button-text-color);
    border-bottom: none;
  }
  .btn-primary {
    background-color: var(--tg-theme-button-color);
    border-color: var(--tg-theme-button-color);
    color: var(--tg-theme-button-text-color);
  }
  .btn-outline-primary {
    color: var(--tg-theme-button-color);
    border-color: var(--tg-theme-button-color);
  }
  .btn-outline-primary:hover {
    background-color: var(--tg-theme-button-color);
    color: var(--tg-theme-button-text-color);
  }
  .btn-outline-secondary {
    color: var(--tg-theme-hint-color);
    border-color: var(--tg-theme-hint-color);
  }
  .btn-outline-secondary:hover {
    background-color: var(--tg-theme-hint-color);
    color: var(--tg-theme-button-text-color);
  }
  .text-muted {
    color: var(--tg-theme-hint-color) !important;
  }
  .spinner-border {
    color: var(--tg-theme-button-color) !important;
  }
  .weather-icon {
    font-size: 4rem;
    line-height: 1;
  }
  .temperature {
    font-size: 3.5rem;
    font-weight: 300;
    line-height: 1.2;
  }
  .detail-item {
    background-color: var(--tg-theme-section-bg-color);
    border-radius: 12px;
    padding: 12px 8px;
    text-align: center;
    height: 100%;
    display: flex;
    flex-direction: column;
    justify-content: center;
  }
  .detail-item i {
    font-size: 1.5rem;
    margin-bottom: 4px;
    color: var(--tg-theme-button-color);
  }
  .detail-item .value {
    font-size: 1.2rem;
    font-weight: 500;
  }
  .detail-item .label {
    font-size: 0.8rem;
    color: var(--tg-theme-hint-color);
  }
  .forecast-hour-card {
    background-color: var(--tg-theme-section-bg-color);
    border-radius: 12px;
    padding: 8px;
    text-align: center;
    min-width: 80px;
    cursor: pointer;
    transition: transform 0.2s, background-color 0.2s;
  }
  .forecast-hour-card:hover {
    transform: translateY(-3px);
    background-color: rgba(var(--tg-theme-button-color-rgb), 0.1);
  }
  .forecast-hour-time {
    font-size: 0.8rem;
    font-weight: 500;
  }
  .forecast-hour-temp {
    font-size: 1rem;
    font-weight: 600;
  }
  #tempChart {
    max-height: 180px;
    width: 100%;
  }
  .timeout-option {
    margin-top: 1rem;
    font-size: 0.9rem;
  }
</style>
@endpush

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
  // ==================== STATE ====================
  let currentState = 'loading';
  let errorMessage = '';
  let usingDefault = true;
  let locationTimeout;

  const hasDefaultLocation = @json($telegramUser && isset($telegramUser->data['default_location']) && !empty($telegramUser->data['default_location']));
  const defaultLocation = @json($telegramUser->data['default_location'] ?? null);

  const appElement = document.getElementById('weatherApp');

  // ==================== HELPER FUNCTIONS ====================
  function clearLocationTimeout() {
    if (locationTimeout) {
      clearTimeout(locationTimeout);
      locationTimeout = null;
    }
  }

  // ==================== FETCH ALL WEATHER DATA (ASYNC/AWAIT) ====================
  async function fetchAllWeather(lat, lon, city = null) {
    currentState = 'loading';
    buildUI();

    try {
      const initData = window.Telegram?.WebApp?.initData || '';
      const body = {};
      if (city) {
        body.city = city;
      } else {
        body.latitude = lat;
        body.longitude = lon;
      }

      // Current weather
      const currentRes = await fetch('{{ secure_url(config("app.url")) }}/api/weather/current', {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          'X-Telegram-Init-Data': initData
        },
        body: JSON.stringify(body)
      });
      const currentData = await currentRes.json();
      if (!currentData.success) {
        throw new Error(currentData.message || 'Gagal memuat cuaca');
      }
      window.weatherData = currentData.data;

      // Setelah mendapatkan currentData dan window.weatherData
      if (window.weatherData.location.latitude && window.weatherData.location.longitude) {
        const aqiRes = await fetch('{{ secure_url(config("app.url")) }}/api/weather/air-quality', {
          method: 'POST',
          headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-Telegram-Init-Data': initData
          },
          body: JSON.stringify({
          latitude: window.weatherData.location.latitude,
          longitude: window.weatherData.location.longitude
          })
        });
        const aqiData = await aqiRes.json();
        if (aqiData.success && aqiData.data) {
          window.aqiData = aqiData.data;
        } else {
          window.aqiData = null;
        }
      }

      const timezoneOffset = window.weatherData.location.timezone_offset || 0;

      // Hourly forecast
      const forecastRes = await fetch('{{ secure_url(config("app.url")) }}/api/weather/hourly-forecast', {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          'X-Telegram-Init-Data': initData
        },
        body: JSON.stringify({
        ...body,
        timezone_offset: timezoneOffset
        })
      });
      const forecastData = await forecastRes.json();
      if (forecastData.success && forecastData.data) {
        window.forecastData = forecastData.data;
        // Pastikan data hourly memiliki detail lengkap
        if (!window.forecastData.hourlyDetails && window.forecastData.hourly) {
          window.forecastData.hourlyDetails = window.forecastData.hourly; // asumsi sudah detail
        }
      } else {
        console.warn('Forecast not available:', forecastData.message);
        window.forecastData = null;
      }

      currentState = 'loaded';
      buildUI();
    } catch (err) {
      console.error('Fetch error:', err);
      currentState = 'error';
      errorMessage = err.message || 'Gagal memuat data cuaca';
      buildUI();
    }
  }

  // ==================== LOCATION HANDLERS ====================
  function initWeather() {
    if (hasDefaultLocation && defaultLocation) {
      usingDefault = true;
      if (defaultLocation.city) {
        fetchAllWeather(null, null, defaultLocation.city);
      } else if (defaultLocation.latitude && defaultLocation.longitude) {
        fetchAllWeather(defaultLocation.latitude, defaultLocation.longitude);
      } else {
        requestLocation();
      }
      return;
    }
    requestLocation();
  }

  function requestLocation() {
    usingDefault = false;
    currentState = 'loading';
    buildUI();

    clearLocationTimeout();
    locationTimeout = setTimeout(() => {
    if (currentState === 'loading') {
    currentState = 'unavailable';
    buildUI();
    }
    }, 10000);

    const tg = window.Telegram?.WebApp;
    if (!tg) {
      useBrowserGeolocation();
      return;
    }

    if (tg.LocationManager && typeof tg.LocationManager.getLocation === 'function') {
      try {
        tg.LocationManager.getLocation((locationData) => {
        clearLocationTimeout();
        if (locationData) {
        fetchAllWeather(locationData.latitude, locationData.longitude);
        } else {
        currentState = 'denied';
        buildUI();
        }
        });
      } catch (e) {
        console.error('Telegram LocationManager error:', e);
        clearLocationTimeout();
        useBrowserGeolocation();
      }
    } else {
      useBrowserGeolocation();
    }
  }

  function useBrowserGeolocation() {
    if (navigator.geolocation) {
      navigator.geolocation.getCurrentPosition(
      (position) => {
      clearLocationTimeout();
      fetchAllWeather(position.coords.latitude, position.coords.longitude);
      },
      (error) => {
      clearLocationTimeout();
      console.error('Geolocation error:', error);
      currentState = error.code === error.PERMISSION_DENIED ? 'denied' : 'unavailable';
      buildUI();
      },
      { enableHighAccuracy: true, timeout: 8000 }
      );
    } else {
      clearLocationTimeout();
      currentState = 'unavailable';
      buildUI();
    }
  }

  window.refreshWeather = async function() {
    if (window.weatherData) {
      const loc = window.weatherData.location;
      usingDefault = false;
      // Nonaktifkan tombol refresh sementara (opsional)
      const refreshBtn = document.querySelector('#weatherApp .btn-outline-primary');
      if (refreshBtn) refreshBtn.disabled = true;
      await fetchAllWeather(loc.latitude, loc.longitude);
      if (refreshBtn) refreshBtn.disabled = false;
    } else {
      initWeather();
    }
  };

  function useDefaultLocation() {
    if (hasDefaultLocation && defaultLocation) {
      usingDefault = true;
      if (defaultLocation.city) {
        fetchAllWeather(null, null, defaultLocation.city);
      } else if (defaultLocation.latitude && defaultLocation.longitude) {
        fetchAllWeather(defaultLocation.latitude, defaultLocation.longitude);
      }
    }
  }

  // ==================== UI RENDERING ====================
  function buildUI() {
    if (currentState === 'loading') {
      appElement.innerHTML = `<div class="text-center py-5"><div class="spinner-border"><span class="visually-hidden">Memuat...</span></div><p class="mt-3">Memuat data cuaca...</p><div class="timeout-option"><button class="btn btn-sm btn-outline-secondary" onclick="showManualInput()"><i class="bi bi-pencil me-1"></i>Input Manual</button></div></div>`;
      return;
    }
    if (currentState === 'denied') {
      appElement.innerHTML = `<div class="text-center py-4"><i class="bi bi-geo-alt-fill text-danger" style="font-size: 3rem;"></i><h5 class="mt-3">Akses Lokasi Ditolak</h5><p class="text-muted">Untuk menampilkan cuaca terkini, kami memerlukan akses lokasi Anda.</p><button class="btn btn-primary" onclick="openLocationSettings()"><i class="bi bi-gear me-2"></i>Buka Pengaturan</button><button class="btn btn-outline-secondary mt-2" onclick="initWeather()"><i class="bi bi-arrow-repeat me-2"></i>Coba Lagi</button><hr class="my-4"><p class="text-muted">Atau masukkan lokasi manual:</p><button class="btn btn-outline-primary" onclick="showManualInput()"><i class="bi bi-pencil me-2"></i>Input Manual</button></div>`;
      return;
    }
    if (currentState === 'unavailable') {
      appElement.innerHTML = `<div class="text-center py-4"><i class="bi bi-exclamation-triangle-fill text-warning" style="font-size: 3rem;"></i><h5 class="mt-3">Lokasi Tidak Tersedia</h5><p class="text-muted">Fitur lokasi tidak didukung atau waktu permintaan habis. Silakan masukkan lokasi secara manual.</p><button class="btn btn-primary" onclick="showManualInput()"><i class="bi bi-pencil me-2"></i>Input Manual</button></div>`;
      return;
    }
    if (currentState === 'manual') {
      appElement.innerHTML = `<div><h5 class="mb-3">Masukkan Lokasi</h5><div class="mb-3"><label for="city" class="form-label">Nama Kota</label><input type="text" class="form-control" id="city" placeholder="Contoh: Jakarta"></div><div class="row"><div class="col-md-6 mb-3"><label for="latitude" class="form-label">Latitude</label><input type="number" step="any" class="form-control" id="latitude" placeholder="-6.2088"></div><div class="col-md-6 mb-3"><label for="longitude" class="form-label">Longitude</label><input type="number" step="any" class="form-control" id="longitude" placeholder="106.8456"></div></div><button class="btn btn-success w-100" onclick="getManualLocation()"><i class="bi bi-search me-2"></i>Cari Cuaca</button><button class="btn btn-link mt-2" onclick="resetToInitial()">Kembali</button></div>`;
      return;
    }
    if (currentState === 'error') {
      appElement.innerHTML = `<div class="text-center py-4"><i class="bi bi-exclamation-circle-fill text-danger" style="font-size: 3rem;"></i><h5 class="mt-3">Terjadi Kesalahan</h5><p class="text-muted">${errorMessage}</p><button class="btn btn-primary" onclick="initWeather()"><i class="bi bi-arrow-repeat me-2"></i>Coba Lagi</button><button class="btn btn-outline-secondary mt-2" onclick="showManualInput()"><i class="bi bi-pencil me-2"></i>Input Manual</button></div>`;
      return;
    }
    if (currentState === 'loaded' && window.weatherData) {
      const w = window.weatherData;
      const iconUrl = `https://openweathermap.org/img/wn/${w.weather.icon}@2x.png`;
      let html = `<div>
      <div class="text-center mb-4">
      <h5>${w.location.name}, ${w.location.country}</h5>
      <div class="weather-icon my-2"><img src="${iconUrl}" alt="${w.weather.description}" style="width: 80px; height: 80px;"></div>
      <div class="temperature">${w.current.temperature}°C</div>
      <div class="text-muted text-uppercase">${w.weather.description}</div>
      <div class="mt-1">Terasa seperti ${w.current.feels_like}°C</div>
      </div>
      <div class="row g-2 mb-3">
      <div class="col-4"><div class="detail-item"><i class="bi bi-droplet"></i><div class="value">${w.current.humidity}%</div><div class="label">Kelembaban</div></div></div>
      <div class="col-4"><div class="detail-item"><i class="bi bi-wind"></i><div class="value">${(w.current.wind_speed * 3.6).toFixed(1)} Km/h</div><div class="label">Angin</div></div></div>
      <div class="col-4"><div class="detail-item"><i class="bi bi-cloud"></i><div class="value">${w.current.clouds}%</div><div class="label">Awan</div></div></div>
      </div>
      <div class="row g-2 mb-3">
      <div class="col-6"><div class="detail-item"><i class="bi bi-sunrise"></i><div class="value">${w.sun.rise}</div><div class="label">Terbit</div></div></div>
      <div class="col-6"><div class="detail-item"><i class="bi bi-sunset"></i><div class="value">${w.sun.set}</div><div class="label">Terbenam</div></div></div>
      </div>
      <div class="row g-2 mb-3">
      <div class="col-6"><div class="detail-item"><i class="bi bi-speedometer2"></i><div class="value">${w.current.pressure} mbar</div><div class="label">Tekanan</div></div></div>
      <div class="col-6"><div class="detail-item"><i class="bi bi-eye"></i><div class="value">${w.current.visibility ? (w.current.visibility/1000).toFixed(1): '-'} km</div><div class="label">Jarak Pandang</div></div></div>
      </div>`;

      // AQI Section
      if (window.aqiData) {
        const aqi = window.aqiData;
        let aqiColor = '#198754'; // hijau untuk baik
        if (aqi.aqi === 2) aqiColor = '#ffc107'; // kuning
        else if (aqi.aqi === 3) aqiColor = '#fd7e14'; // oranye
        else if (aqi.aqi >= 4) aqiColor = '#dc3545'; // merah

        html += `<hr>
        <div class="mb-3">
        <h6><i class="bi bi-activity me-2"></i>Kualitas Udara (AQI)</h6>
        <div class="detail-item" style="background-color: rgba(var(--tg-theme-button-color-rgb), 0.05);">
        <div class="value" style="color: ${aqiColor};">${aqi.level}</div>
        <div class="label">Indeks: ${aqi.aqi}</div>
        <div class="small text-muted mt-2">${aqi.recommendation}</div>
        <div class="row g-1 mt-2">
        <div class="col-4"><span class="small">PM2.5: ${aqi.components.pm2_5} μg/m³</span></div>
        <div class="col-4"><span class="small">PM10: ${aqi.components.pm10} μg/m³</span></div>
        <div class="col-4"><span class="small">O3: ${aqi.components.o3} μg/m³</span></div>
        </div>
        </div>
        </div>`;
      }

      // Forecast section dengan klik
      if (window.forecastData && window.forecastData.hourly && window.forecastData.hourly.length) {
        html += `<hr><h6 class="mt-3 mb-3"><i class="bi bi-clock-history me-2"></i>Perkiraan 24 Jam Ke Depan</h6>
        <div class="d-flex flex-nowrap overflow-auto gap-2 pb-2" style="scrollbar-width: thin;">`;
        window.forecastData.hourly.forEach((item, idx) => {
        const pop = item.pop || 0;
        const popIcon = pop >= 70 ? 'bi-droplet-fill' : (pop >= 30 ? 'bi-droplet-half' : 'bi-droplet');
        html += `<div class="forecast-hour-card" data-forecast-index="${idx}" onclick="showForecastDetail(${idx})">
        <div class="forecast-hour-time">${item.time}</div>
        <img src="https://openweathermap.org/img/wn/${item.icon}.png" width="40" height="40" alt="${item.description}">
        <div class="forecast-hour-temp">${item.temp}°C</div>
        <div class="small text-muted">${item.description?.substring(0,3)}</div>
        ${pop > 0 ? `<div class="small text-secondary"><i class="bi ${popIcon}"></i>${pop}%</div>` : ''}
        </div>`;
        });
        html += `</div>`;
        if (window.forecastData.chart && window.forecastData.chart.labels) {
          html += `<div class="mt-3"><canvas id="tempChart" height="150"></canvas></div>`;
        }
      } else {
        html += `<div class="alert alert-warning mt-3">Data forecast tidak tersedia saat ini.</div>`;
      }

      html += `<div class="text-muted small text-center mb-3"><i class="bi bi-clock me-1"></i>Diperbarui: ${new Date(w.updated_at).toLocaleTimeString('id-ID')}</div>
      <div class="d-flex gap-2">
      <button class="btn btn-outline-primary flex-grow-1" onclick="refreshWeather()"><i class="bi bi-arrow-repeat me-2"></i>Perbarui</button>
      ${!usingDefault && hasDefaultLocation ? `<button class="btn btn-outline-secondary" onclick="useDefaultLocation()"><i class="bi bi-house me-2"></i>Kembali ke Default</button>`: ''}
      </div>
      </div>`;

      appElement.innerHTML = html;
      if (window.forecastData && window.forecastData.chart && window.forecastData.chart.labels) {
        drawChart(window.forecastData.chart);
      }
    }
  }

  // ==================== FORECAST DETAIL MODAL ====================
  function showForecastDetail(index) {
    const item = window.forecastData?.hourly?.[index];
    if (!item) return;

    const modalBody = document.getElementById('forecastDetailBody');
    if (!modalBody) return;

    const details = item.details || item; // fallback
    const pop = details.pop || 0;

    const html = `
    <div class="text-center mb-3">
    <img src="https://openweathermap.org/img/wn/${details.icon}@2x.png" width="80" height="80" alt="${details.description}">
    <h4>${details.temp}°C</h4>
    <p class="text-muted">${details.description}</p>
    </div>
    <div class="row g-2">
    <div class="col-6">
    <div class="detail-item">
    <i class="bi bi-thermometer-half"></i>
    <div class="value">${details.feels_like ?? '-'}°C</div>
    <div class="label">Terasa seperti</div>
    </div>
    </div>
    <div class="col-6">
    <div class="detail-item">
    <i class="bi bi-droplet"></i>
    <div class="value">${details.humidity ?? '-'}%</div>
    <div class="label">Kelembaban</div>
    </div>
    </div>
    <div class="col-6">
    <div class="detail-item">
    <i class="bi bi-droplet-half"></i>
    <div class="value">${pop}%</div>
    <div class="label">Peluang Hujan</div>
    </div>
    </div>
    <div class="col-6">
    <div class="detail-item">
    <i class="bi bi-speedometer2"></i>
    <div class="value">${details.pressure ?? '-'} mbar</div>
    <div class="label">Tekanan</div>
    </div>
    </div>
    <div class="col-6">
    <div class="detail-item">
    <i class="bi bi-wind"></i>
    <div class="value">${details.wind_speed ? (details.wind_speed * 3.6).toFixed(1): '-'} Km/h</div>
    <div class="label">Angin</div>
    </div>
    </div>
    <div class="col-6">
    <div class="detail-item">
    <i class="bi bi-cloud"></i>
    <div class="value">${details.clouds ?? '-'}%</div>
    <div class="label">Awan</div>
    </div>
    </div>
    <div class="col-6">
    <div class="detail-item">
    <i class="bi bi-eye"></i>
    <div class="value">${details.visibility ? (details.visibility/1000).toFixed(1): '-'} km</div>
    <div class="label">Jarak Pandang</div>
    </div>
    </div>
    </div>
    `;

    modalBody.innerHTML = html;
    const modal = new bootstrap.Modal(document.getElementById('forecastDetailModal'));
    modal.show();
  }

  // ==================== CHART DRAWING (FIXED) ====================
  function drawChart(chartData) {
    const canvas = document.getElementById('tempChart');
    if (!canvas) {
      console.warn('Canvas element not found');
      return;
    }
    if (window.tempChart) {
      try {
        if (typeof window.tempChart.destroy === 'function') {
          window.tempChart.destroy();
        }
      } catch (e) {
        console.warn('Error destroying previous chart:', e);
      }
      window.tempChart = null;
    }
    window.tempChart = new Chart(canvas, {
    type: 'line',
    data: {
    labels: chartData.labels,
    datasets: [{
    label: 'Suhu (°C)',
    data: chartData.temps,
    borderColor: 'rgb(75, 192, 192)',
    backgroundColor: 'rgba(75, 192, 192, 0.2)',
    tension: 0.3,
    fill: true,
    pointBackgroundColor: 'rgb(75, 192, 192)',
    pointBorderColor: '#fff',
    pointRadius: 4,
    pointHoverRadius: 6
    }]
    },
    options: {
    responsive: true,
    maintainAspectRatio: true,
    plugins: {
    legend: { display: false },
    tooltip: { callbacks: { label: (ctx) => `${ctx.raw}°C` } }
    },
    scales: {
    y: { beginAtZero: false, title: { display: true, text: 'Suhu (°C)' } },
    x: { title: { display: true, text: 'Waktu' } }
    }
    }
    });
  }

  // ==================== MANUAL INPUT & UTILITIES ====================
  window.showManualInput = function() {
    clearLocationTimeout();
    currentState = 'manual';
    buildUI();
  };

  window.resetToInitial = function() {
    initWeather();
  };

  window.getManualLocation = function() {
    const lat = document.getElementById('latitude')?.value;
    const lon = document.getElementById('longitude')?.value;
    const city = document.getElementById('city')?.value;

    if (lat && lon) {
      usingDefault = false;
      fetchAllWeather(parseFloat(lat), parseFloat(lon), null);
    } else if (city) {
      usingDefault = false;
      fetchAllWeather(null, null, city);
    } else {
      alert('Masukkan kota atau koordinat yang valid.');
    }
  };

  window.openLocationSettings = function() {
    const tg = window.Telegram?.WebApp;
    if (tg?.LocationManager) {
      tg.LocationManager.openSettings();
    } else {
      alert('Silakan buka pengaturan Telegram dan izinkan akses lokasi.');
    }
  };

  // ==================== START ====================
  document.addEventListener('DOMContentLoaded', function() {
  initWeather();
  document.getElementById('changeLocationBtn').addEventListener('click', () => showManualInput());
  });
</script>
@endpush