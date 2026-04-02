<?php

namespace Modules\Weather\Http\Controllers;

use Illuminate\Routing\Controller;
use Modules\Weather\Services\WeatherService;
use Modules\Telegram\Models\TelegramUser;
use Illuminate\Http\Request;

class WeatherController extends Controller
{
  protected WeatherService $weatherService;

  public function __construct(WeatherService $weatherService) {
    $this->weatherService = $weatherService;
  }

  /**
  * Tampilkan halaman utama weather.
  */
  public function index(Request $request) {
    $tgUser = $request->get('telegram_user'); // Dari middleware
    $telegramUser = TelegramUser::find($tgUser["id"]);

    return view('weather::index', compact('telegramUser'));
  }

  public function settings(Request $request) {
    $tgUser = $request->get('telegram_user'); // Dari middleware
    $telegramUser = TelegramUser::find($tgUser["id"]);
    $settings = $this->weatherService->getUserSettings($telegramUser->id);
    return view("weather::settings", compact("telegramUser", "settings"));
  }

  /**
  * API untuk mendapatkan data cuaca.
  * Bisa berdasarkan pengguna yang sudah login (dari middleware) atau input manual.
  */
  public function getWeather(Request $request) {
    $telegramUser = $request->get('telegram_user'); // Bisa null jika tidak ada

    $data = null;

    // 1. Jika ada user dan dia punya lokasi default, gunakan itu
    if ($telegramUser && $this->userHasDefaultLocation($telegramUser)) {
      $data = $this->weatherService->getWeatherForUser($telegramUser);
    }
    // 2. Jika tidak, coba gunakan input manual dari request
    else {
      $city = $request->input('city');
      $lat = $request->input('latitude');
      $lon = $request->input('longitude');

      $data = $this->weatherService->getWeatherByInput($city, $lat, $lon);
    }
    \Log::debug("Current", ['data' => $data]);

    if (!$data) {
      return response()->json([
        'success' => false,
        'message' => 'Tidak dapat memperoleh data cuaca. Periksa kembali lokasi atau API key.'
      ], 500);
    }

    return response()->json([
      'success' => true,
      'data' => $data
    ]);
  }

  public function getHourlyForecast(Request $request) {
    $telegramUser = $request->get('telegram_user');
    $timezoneOffset = (int) $request->input('timezone_offset', 0);

    $location = null;
    if ($telegramUser && $this->userHasDefaultLocation($telegramUser)) {
      $location = $telegramUser->data['default_location'] ?? null;
    } else {
      $city = $request->input('city');
      $lat = $request->input('latitude');
      $lon = $request->input('longitude');
      if ($city) {
        $location = ['city' => $city];
      } elseif ($lat && $lon) {
        $location = ['latitude' => $lat,
          'longitude' => $lon];
      }
    }

    if (!$location) {
      return response()->json(['success' => false, 'message' => 'Lokasi tidak ditemukan'], 400);
    }

    $data = $this->weatherService->getHourlyForecast($location, $timezoneOffset);
    \Log::debug("Forecast", ["data" => $data]);
    if (!$data) {
      return response()->json(['success' => false, 'message' => 'Data forecast tidak tersedia'], 404);
    }

    return response()->json(['success' => true, 'data' => $data]);
  }

  public function getAirQuality(Request $request) {
    $lat = $request->input('latitude');
    $lon = $request->input('longitude');
    if (!$lat || !$lon) {
      return response()->json(['success' => false, 'message' => 'Koordinat diperlukan'], 400);
    }

    $data = $this->weatherService->getAirQuality((float)$lat, (float)$lon);
    if (!$data) {
      return response()->json(['success' => false, 'message' => 'Data kualitas udara tidak tersedia'], 404);
    }

    return response()->json(['success' => true, 'data' => $data]);
  }

  public function getUVIndex(Request $request) {
    $lat = $request->input('latitude');
    $lon = $request->input('longitude');
    if (!$lat || !$lon) {
      return response()->json(['success' => false, 'message' => 'Koordinat diperlukan'], 400);
    }

    \Log::debug("uv index", ['lat' => $lat, 'lon' => $lon]);
    $data = $this->weatherService->getUVIndex((float)$lat, (float)$lon);
    if (!$data) {
      return response()->json(['success' => false, 'message' => 'Data indeks UV tidak tersedia'], 404);
    }

    return response()->json(['success' => true, 'data' => $data]);
  }

  /**
  * Simpan pengaturan cuaca pengguna.
  */
  public function saveSettings(Request $request) {
    $tgUser = $request->get('telegram_user');
    $telegramUser = TelegramUser::find($tgUser["id"]);

    if (!$telegramUser) {
      return response()->json([
        'success' => false,
        'message' => 'Anda harus membuka halaman ini melalui mini app Telegram.'
      ], 403);
    }

    $request->validate([
      'city' => 'nullable|string|max:255',
      'latitude' => 'nullable|numeric|between:-90,90',
      'longitude' => 'nullable|numeric|between:-180,180',
      'notifications_enabled' => 'boolean',
    ]);

    $locationData = [];
    if ($request->filled('city')) {
      $locationData['city'] = $request->city;
    } elseif ($request->filled('latitude') && $request->filled('longitude')) {
      $locationData['latitude'] = (float) $request->latitude;
      $locationData['longitude'] = (float) $request->longitude;
    } else {
      return response()->json([
        'success' => false,
        'message' => 'Harap isi nama kota atau koordinat.'
      ], 422);
    }

    $this->weatherService->updateUserSettings(
      $telegramUser,
      $locationData,
      $request->boolean('notifications_enabled')
    );

    return response()->json([
      'success' => true,
      'message' => 'Pengaturan cuaca berhasil disimpan.'
    ]);
  }

  public function refresh(Request $request) {
    $request->validate([
      'latitude' => 'nullable|numeric|between:-90,90',
      'longitude' => 'nullable|numeric|between:-180,180',
    ]);

    try {
      $result = $this->weatherService->refresh([
        "latitude" => $request->latitude,
        "longitude" => $request->longitude
      ]);

      return response()->json(["success" => $result ?? false, "message" => "Data refreshed"]);
    } catch (\Exception $e) {
      return response()->json(["success" => false, "message" => $e->getMessage()]);
    }
  }

  /**
  * Cek apakah user memiliki lokasi default.
  */
  protected function userHasDefaultLocation(TelegramUser $user): bool
  {
    $data = $user->data ?? [];
    return !empty($data['default_location']);
  }
}