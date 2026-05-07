// page.js
(function(window, document, undefined) {
  'use strict';

  const Core = window.WeatherAppCore;
  if (!Core) {
    console.error('WeatherAppCore tidak tersedia');
    return;
  }

  // ======================== UTILITY RENDER ========================
  function getWindDirection(deg) {
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
  }

  // Render tampilan cuaca utama
  function renderWeatherView(state) {
    var weatherDiv = document.getElementById('weather-view');
    var settingsDiv = document.getElementById('settings-view');
    var loadingDiv = document.getElementById('loading-view');
    if (!weatherDiv || !settingsDiv || !loadingDiv) return;

    if (state.loading) {
      weatherDiv.style.display = 'none';
      settingsDiv.style.display = 'none';
      loadingDiv.style.display = 'flex';
      return;
    }

    if (state.error) {
      // tampilkan error di weather-view
      var errorHtml = '<div class="error-container">' +
      '<div class="error-message"><i class="bi bi-exclamation-triangle-fill me-2"></i>Gagal memuat data</div>' +
      '<div class="error-detail">' + Core.escapeHtml(state.error) + '</div>' +
      '<button class="btn btn-primary btn-sm mt-3" id="retryBtn">Muat Ulang</button>' +
      '</div>';
      weatherDiv.innerHTML = errorHtml;
      weatherDiv.style.display = 'block';
      settingsDiv.style.display = 'none';
      loadingDiv.style.display = 'none';
      document.getElementById('retryBtn') && document.getElementById('retryBtn').addEventListener('click', function() {
        window.location.reload();
      });
      return;
    }

    // Jika tidak ada data cuaca, tampilkan pesan
    if (!state.weather) {
      weatherDiv.innerHTML = '<div class="alert alert-warning">Data cuaca tidak tersedia</div>';
      weatherDiv.style.display = 'block';
      settingsDiv.style.display = 'none';
      loadingDiv.style.display = 'none';
      return;
    }

    var w = state.weather;
    var iconUrl = 'https://openweathermap.org/img/wn/' + w.weather.icon + '@2x.png';
    var visibilityKm = w.current.visibility ? (w.current.visibility / 1000).toFixed(1): '-';
    var pressureVal = w.current.pressure ? w.current.pressure + ' mBar': '-';
    var windDeg = w.current.wind_deg;
    var windDir = getWindDirection(windDeg);
    var windSpeed = (w.current.wind_speed * 3.6).toFixed(1);

    var html = '<div class="card shadow">' +
    '<div class="card-header d-flex justify-content-between align-items-center">' +
    '<h4 class="mb-0"><i class="bi bi-cloud-sun me-2"></i>Informasi Cuaca</h4>' +
    '<div>' +
    '<button id="settingsBtn" class="btn btn-sm btn-outline-light"><i class="bi bi-gear-fill"></i></button>' +
    '<button id="refreshWeatherBtn" class="btn btn-sm btn-outline-light ms-2"><i class="bi bi-arrow-repeat"></i></button>' +
    '</div></div>' +
    '<div class="card-body">' +
    '<div class="text-center mb-4">' +
    '<h5>' + Core.escapeHtml(w.location.name) + ', ' + Core.escapeHtml(w.location.country) + '</h5>' +
    '<div class="weather-icon my-2"><img src="' + iconUrl + '" alt="' + w.weather.description + '"></div>' +
    '<div class="temperature">' + w.current.temperature + '°C</div>' +
    '<div class="text-muted text-uppercase">' + Core.escapeHtml(w.weather.description) + '</div>' +
    '<div class="mt-1">Terasa ' + w.current.feels_like + '°C</div>' +
    '</div>' +
    '<div class="row g-2 mb-3">' +
    '<div class="col-4"><div class="detail-item"><i class="bi bi-droplet"></i><div class="value">' + w.current.humidity + '%</div><div class="label">Kelembaban</div></div></div>' +
    '<div class="col-4"><div class="detail-item"><i class="bi bi-wind"></i><div class="value">' + windSpeed + ' km/j ' + (windDeg ? '<span style="display:inline-block;transform:rotate(' + windDeg + 'deg)"><i class="bi bi-arrow-up-short"></i></span>': '') + '</div><div class="label">Angin (' + windDir + ')</div></div></div>' +
    '<div class="col-4"><div class="detail-item"><i class="bi bi-cloud"></i><div class="value">' + w.current.clouds + '%</div><div class="label">Awan</div></div></div>' +
    '</div>' +
    '<div class="row g-2 mb-3">' +
    '<div class="col-6"><div class="detail-item"><i class="bi bi-speedometer2"></i><div class="value">' + pressureVal + '</div><div class="label">Tekanan</div></div></div>' +
    '<div class="col-6"><div class="detail-item"><i class="bi bi-eye"></i><div class="value">' + visibilityKm + ' km</div><div class="label">Jarak Pandang</div></div></div>' +
    '</div>' +
    '<div class="row g-2 mb-3">' +
    '<div class="col-6"><div class="detail-item"><i class="bi bi-sunrise"></i><div class="value">' + w.sun.rise + '</div><div class="label">Terbit</div></div></div>' +
    '<div class="col-6"><div class="detail-item"><i class="bi bi-sunset"></i><div class="value">' + w.sun.set + '</div><div class="label">Terbenam</div></div></div>' +
    '</div>';

    if (state.aqi) {
      html += '<hr><div class="mb-3"><h6><i class="bi bi-activity me-2"></i>Kualitas Udara (AQI)</h6>' +
      '<div class="detail-item"><div class="value">' + state.aqi.level + '</div>' +
      '<div class="label">Indeks: ' + state.aqi.aqi + '</div>' +
      '<div class="small text-muted mt-1">' + state.aqi.recommendation + '</div></div></div>';
    }
    if (state.uv) {
      html += '<div class="mb-3"><h6><i class="bi bi-brightness-high me-2"></i>Indeks UV</h6>' +
      '<div class="detail-item"><div class="value" style="color: ' + state.uv.color + ';">' + state.uv.uvi + ' - ' + state.uv.level + '</div>' +
      '<div class="small text-muted">' + state.uv.recommendation + '</div></div></div>';
    }
    if (state.forecast && state.forecast.hourly && state.forecast.hourly.length) {
      html += '<hr><h6 class="mt-3 mb-3"><i class="bi bi-clock-history me-2"></i>Perkiraan 24 Jam</h6>' +
      '<div class="d-flex flex-nowrap overflow-auto gap-2 pb-2">';
      state.forecast.hourly.forEach(function(item, idx) {
        var pop = item.pop || 0;
        var popIcon = pop >= 70 ? 'bi-droplet-fill': (pop >= 30 ? 'bi-droplet-half': 'bi-droplet');
        var desc = item.description ? item.description.substring(0, 3): '';
        html += '<div class="forecast-hour-card" data-index="' + idx + '">' +
        '<div class="forecast-hour-time">' + item.time + '</div>' +
        '<img src="https://openweathermap.org/img/wn/' + item.icon + '.png" width="40" height="40">' +
        '<div class="forecast-hour-temp">' + item.temp + '°C</div>' +
        '<div class="small text-muted">' + desc + '</div>';
        if (pop > 0) html += '<div class="small"><i class="bi ' + popIcon + '"></i>' + pop.toFixed(2) + '%</div>';
        html += '</div>';
      });
      html += '</div>';
      if (state.forecast.chart && state.forecast.chart.labels) {
        html += '<div class="mt-3"><canvas id="tempChart" height="150"></canvas></div>';
      }
    } else {
      html += '<div class="alert alert-warning mt-3">Data forecast tidak tersedia.</div>';
    }
    html += '<div class="text-muted small text-center mt-3"><i class="bi bi-clock me-1"></i>Diperbarui: ' + new Date(w.updated_at).toLocaleTimeString() + '</div>' +
    '</div></div>';

    weatherDiv.innerHTML = html;
    weatherDiv.style.display = 'block';
    settingsDiv.style.display = 'none';
    loadingDiv.style.display = 'none';

    // Gambar chart jika ada
    if (state.forecast && state.forecast.chart && state.forecast.chart.labels) {
      setTimeout(function() {
        drawChart(state.forecast.chart);
      }, 50);
    }
  }

  // Render halaman pengaturan
  function renderSettingsView(state) {
    var weatherDiv = document.getElementById('weather-view');
    var settingsDiv = document.getElementById('settings-view');
    var loadingDiv = document.getElementById('loading-view');
    if (!weatherDiv || !settingsDiv || !loadingDiv) return;

    var settings = state.settings || {};
    var city = settings.city || '';
    var lat = settings.latitude !== undefined ? settings.latitude: '';
    var lon = settings.longitude !== undefined ? settings.longitude: '';
    var notifications = settings.notifications_enabled || false;

    var html = '<div class="card shadow">' +
    '<div class="card-header d-flex justify-content-between align-items-center">' +
    '<h4 class="mb-0"><i class="bi bi-gear-fill me-2"></i>Pengaturan Cuaca</h4>' +
    '<button id="backToWeatherBtn" class="btn btn-sm btn-outline-light"><i class="bi bi-arrow-left"></i> Kembali</button>' +
    '</div>' +
    '<div class="card-body">' +
    '<form id="settingsForm">' +
    '<h5>Lokasi Default</h5>' +
    '<p class="text-muted small">Kosongkan untuk meminta lokasi setiap kali.</p>' +
    '<div class="mb-3">' +
    '<label class="form-label">Nama Kota</label>' +
    '<input type="text" class="form-control" id="city" name="city" value="' + Core.escapeHtml(city) + '" placeholder="Contoh: Jakarta">' +
    '</div>' +
    '<div class="row">' +
    '<div class="col-md-6 mb-3">' +
    '<label class="form-label">Latitude</label>' +
    '<input type="number" step="any" class="form-control" id="latitude" value="' + Core.escapeHtml(lat) + '" placeholder="-6.2088">' +
    '</div>' +
    '<div class="col-md-6 mb-3">' +
    '<label class="form-label">Longitude</label>' +
    '<input type="number" step="any" class="form-control" id="longitude" value="' + Core.escapeHtml(lon) + '" placeholder="106.8456">' +
    '</div>' +
    '</div>' +
    '<div class="mb-3">' +
    '<button type="button" class="btn btn-outline-primary" id="autoLocationBtn"><i class="bi bi-geo-alt me-2"></i>Ambil lokasi saat ini</button>' +
    '<span class="text-muted ms-2" id="locationStatus"></span>' +
    '</div>' +
    '<hr>' +
    '<div class="form-check form-switch mb-3">' +
    '<input class="form-check-input" type="checkbox" id="notifications_enabled" ' + (notifications ? 'checked': '') + ' value="1">' +
    '<label class="form-check-label" for="notifications_enabled">Aktifkan notifikasi cuaca harian</label>' +
    '</div>' +
    '<button type="submit" class="btn btn-primary w-100">Simpan Pengaturan</button>' +
    '</form>' +
    '</div></div>';

    settingsDiv.innerHTML = html;
    weatherDiv.style.display = 'none';
    settingsDiv.style.display = 'block';
    loadingDiv.style.display = 'none';
  }

  // Fungsi untuk menampilkan modal detail forecast
  function showForecastDetail(index, state) {
    var item = state.forecast && state.forecast.hourly ? state.forecast.hourly[index]: null;
    if (!item) {
      Core.showToast('Data forecast tidak ditemukan', 'danger');
      return;
    }
    var details = item.details || item;
    var pop = details.pop || 0;
    var windSpeed = details.wind_speed ? (details.wind_speed * 3.6).toFixed(1): '-';
    var windDeg = details.wind_deg;
    var windDir = getWindDirection(windDeg);
    var pressure = details.pressure ? details.pressure + ' mBar': '-';
    var visibility = details.visibility ? (details.visibility / 1000).toFixed(1) + ' km': '-';
    var uv = details.uvi !== undefined ? details.uvi: '-';
    var clouds = details.clouds !== undefined ? details.clouds + '%': '-';
    var feelsLike = details.feels_like !== undefined ? details.feels_like: '-';
    var humidity = details.humidity !== undefined ? details.humidity: '-';

    var html = '<div class="text-center mb-3">' +
    '<div class="text-muted small">' + (details.date || '') + ' ' + (details.time || '') + '</div>' +
    '<img src="https://openweathermap.org/img/wn/' + details.icon + '@4x.png" width="80" height="80">' +
    '<h4>' + details.temp + '°C</h4>' +
    '<p class="text-muted">' + details.description + '</p>' +
    '</div>' +
    '<div class="row g-2">' +
    '<div class="col-6"><div class="detail-item"><i class="bi bi-thermometer-half"></i><div class="value">' + feelsLike + '°C</div><div class="label">Terasa</div></div></div>' +
    '<div class="col-6"><div class="detail-item"><i class="bi bi-droplet"></i><div class="value">' + humidity + '%</div><div class="label">Kelembaban</div></div></div>' +
    '<div class="col-6"><div class="detail-item"><i class="bi bi-droplet-half"></i><div class="value">' + pop + '%</div><div class="label">Peluang Hujan</div></div></div>' +
    '<div class="col-6"><div class="detail-item"><i class="bi bi-wind"></i><div class="value">' + windSpeed + ' km/j ' + (windDeg ? '<span style="display:inline-block;transform:rotate(' + windDeg + 'deg)"><i class="bi bi-arrow-up-short"></i></span>': '') + '</div><div class="label">Angin (' + windDir + ')</div></div></div>' +
    '<div class="col-6"><div class="detail-item"><i class="bi bi-speedometer2"></i><div class="value">' + pressure + '</div><div class="label">Tekanan</div></div></div>' +
    '<div class="col-6"><div class="detail-item"><i class="bi bi-eye"></i><div class="value">' + visibility + '</div><div class="label">Jarak Pandang</div></div></div>' +
    '<div class="col-6"><div class="detail-item"><i class="bi bi-brightness-high"></i><div class="value">' + uv + '</div><div class="label">Indeks UV</div></div></div>' +
    '<div class="col-6"><div class="detail-item"><i class="bi bi-cloud"></i><div class="value">' + clouds + '</div><div class="label">Awan</div></div></div>' +
    '</div>';

    var modalBody = document.getElementById('forecastModalBody');
    if (modalBody) modalBody.innerHTML = html;
    var modalEl = document.getElementById('forecastModal');
    if (modalEl) {
      var modal = new bootstrap.Modal(modalEl);
      modal.show();
    } else {
      console.error('Modal element not found');
    }
  }

  function drawChart(chartData) {
    var canvas = document.getElementById('tempChart');
    if (!canvas) {
      setTimeout(function() {
        drawChart(chartData);
      }, 100);
      return;
    }
    if (window.tempChart && typeof window.tempChart.destroy === 'function') {
      window.tempChart.destroy();
    }
    if (typeof Chart === 'undefined') {
      setTimeout(function() {
        drawChart(chartData);
      }, 200);
      return;
    }
    var buttonColor = getComputedStyle(document.documentElement).getPropertyValue('--tg-theme-button-color').trim() || '#007aff';
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
              label: function(ctx) {
                return ctx.raw + '°C';
              }
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
  }

  // Ekspos fungsi render ke global agar bisa dipanggil dari main.js
  window.WeatherAppUI = {
    renderWeatherView: renderWeatherView,
    renderSettingsView: renderSettingsView,
    showForecastDetail: showForecastDetail,
    drawChart: drawChart // meskipun internal, diekspos untuk jaga-jaga
  };
})(window, document);