@extends('coreui::layouts.mini-app')
@section('title', 'Pengaturan Cuaca')

@section('content')
<div class="container py-4">
  <div class="row justify-content-center mb-3">
    <div class="col-md-12">
      <div class="d-flex justify-content-between align-items-center">
        <a href="{{ route('apps.weather') }}" class="btn btn-outline-secondary">
          <i class="bi bi-arrow-left me-2"></i>Kembali
        </a>
      </div>
    </div>
  </div>

  <div class="row justify-content-center">
    <div class="col-md-8 col-lg-6">
      <div class="card shadow">
        <div class="card-header bg-primary text-white">
          <h4 class="mb-0"><i class="bi bi-gear-fill me-2"></i>Pengaturan Cuaca</h4>
        </div>
        <div class="card-body">
          <form id="settingsForm">
            @csrf
            <h5>Lokasi Default</h5>
            <p class="text-muted small">
              Lokasi ini akan digunakan saat membuka halaman cuaca. Kosongkan untuk meminta lokasi setiap kali.
            </p>

            <div class="mb-3">
              <label for="city" class="form-label">Nama Kota</label>
              <input type="text" class="form-control" id="city" name="city"
              value="{{ old('city', $settings->city ?? '') }}"
              placeholder="Contoh: Jakarta">
              <div class="form-text">
                Atau isi koordinat di bawah untuk lokasi yang lebih spesifik.
              </div>
            </div>

            <div class="row">
              <div class="col-md-6 mb-3">
                <label for="latitude" class="form-label">Latitude</label>
                <input type="number" step="any" class="form-control" id="latitude" name="latitude"
                value="{{ old('latitude', $settings->latitude ?? '') }}"
                placeholder="-6.2088">
              </div>
              <div class="col-md-6 mb-3">
                <label for="longitude" class="form-label">Longitude</label>
                <input type="number" step="any" class="form-control" id="longitude" name="longitude"
                value="{{ old('longitude', $settings->longitude ?? '') }}"
                placeholder="106.8456">
              </div>
            </div>

            <div class="mb-3">
              <button type="button" class="btn btn-outline-primary" id="autoLocationBtn">
                <i class="bi bi-geo-alt me-2"></i>Ambil lokasi saat ini
              </button>
              <small class="text-muted ms-2" id="locationStatus"></small>
            </div>

            <hr>
            <h5>Notifikasi</h5>
            <div class="form-check form-switch mb-3">
              <input class="form-check-input" type="checkbox" id="notifications_enabled" name="notifications_enabled"
              {{ old('notifications_enabled', $settings->notifications_enabled ?? false) ? 'checked' : '' }} value="1">
              <label class="form-check-label" for="notifications_enabled">
                Aktifkan notifikasi cuaca harian
              </label>
              <div class="form-text text-muted">
                Akan dikirim setiap pagi pukul 06:00.
              </div>
            </div>

            <button type="submit" class="btn btn-primary w-100" id="saveBtn">
              Simpan Pengaturan
            </button>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>
@endsection

@push('styles')
<style>
  /* (gaya sama seperti sebelumnya, atau bisa gunakan yang sudah ada) */
</style>
@endpush

@push('scripts')
<script>
  const form = document.getElementById('settingsForm');
  const saveBtn = document.getElementById('saveBtn');
  const autoLocationBtn = document.getElementById('autoLocationBtn');
  const locationStatus = document.getElementById('locationStatus');

  function requestCurrentLocation() {
    locationStatus.innerText = "Meminta lokasi...";
    autoLocationBtn.disabled = true;

    const useBrowserGeolocation = () => {
      if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
        (position) => {
        document.getElementById('latitude').value = position.coords.latitude;
        document.getElementById('longitude').value = position.coords.longitude;
        document.getElementById('city').value = "";
        locationStatus.innerText = "Lokasi berhasil didapatkan.";
        autoLocationBtn.disabled = false;
        },
        (error) => {
        let msg = "Gagal: ";
        if (error.code === error.PERMISSION_DENIED) {
        msg += "Izin ditolak";
        } else if (error.code === error.TIMEOUT) {
        msg += "Waktu habis";
        } else {
        msg += error.message;
        }
        locationStatus.innerText = msg;
        autoLocationBtn.disabled = false;
        },
        { enableHighAccuracy: true, timeout: 10000 }
        );
      } else {
        locationStatus.innerText = "Geolocation tidak didukung";
        autoLocationBtn.disabled = false;
      }
    };

    const tg = window.Telegram?.WebApp;
    if (!tg) {
      useBrowserGeolocation();
      return;
    }

    if (!tg.LocationManager || typeof tg.LocationManager.getLocation !== 'function') {
      useBrowserGeolocation();
      return;
    }

    const timeout = setTimeout(() => {
    locationStatus.innerText = "Timeout Telegram, mencoba browser...";
    useBrowserGeolocation();
    }, 10000);

    try {
      tg.LocationManager.getLocation((locationData) => {
      clearTimeout(timeout);
      if (locationData) {
      document.getElementById('latitude').value = locationData.latitude;
      document.getElementById('longitude').value = locationData.longitude;
      document.getElementById('city').value = "";
      locationStatus.innerText = "Lokasi berhasil didapatkan dari Telegram.";
      autoLocationBtn.disabled = false;
      } else {
      locationStatus.innerText = "Akses lokasi ditolak, mencoba browser...";
      useBrowserGeolocation();
      }
      });
    } catch (e) {
      clearTimeout(timeout);
      console.error("Location Manager error:", e);
      locationStatus.innerText = "Error, mencoba browser...";
      useBrowserGeolocation();
    }
  }

  autoLocationBtn.addEventListener("click", requestCurrentLocation);

  form.addEventListener('submit', async function(e) {
  e.preventDefault();
  e.stopPropagation();

  saveBtn.disabled = true;
  saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Menyimpan...';

  const city = document.getElementById('city').value;
  const latitude = document.getElementById('latitude').value;
  const longitude = document.getElementById('longitude').value;
  const notificationsEnabled = document.getElementById('notifications_enabled').checked;

  const formData = {
  city: city || undefined,
  latitude: latitude ? parseFloat(latitude) : undefined,
  longitude: longitude ? parseFloat(longitude) : undefined,
  notifications_enabled: notificationsEnabled
  };

  Object.keys(formData).forEach(key => formData[key] === undefined && delete formData[key]);

  try {
  const response = await fetch('{{ secure_url(config("app.url")) }}/api/weather/settings', {
  method: 'POST',
  headers: {
  'Accept': 'application/json',
  'Content-Type': 'application/json',
  'X-Telegram-Init-Data': window.Telegram?.WebApp?.initData || ''
  },
  body: JSON.stringify(formData)
  });

  const result = await response.json();

  if (result.success) {
  showToast(result.message, 'success');
  } else {
  let errorMsg = result.message || 'Terjadi kesalahan.';
  if (result.errors) {
  errorMsg = Object.values(result.errors).flat().join('<br>');
  }
  showToast(errorMsg, 'danger');
  }
  } catch (error) {
  console.error('Error:', error);
  showToast('Gagal terhubung ke server.', 'danger');
  } finally {
  saveBtn.disabled = false;
  saveBtn.innerHTML = 'Simpan Pengaturan';
  }
  });
</script>
@endpush