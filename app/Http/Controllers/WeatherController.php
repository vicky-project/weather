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
    $tg = $request->get('telegram_user'); // Dari middleware
    $telegramUser = TelegramUser::find($tgUser["id"]);

    return view('weather::index', compact('telegramUser'));
  }

  /**
  * API untuk mendapatkan data cuaca.
  * Bisa berdasarkan pengguna yang sudah login (dari middleware) atau input manual.
  */
  public function getWeather(Request $request) {
    $tg = $request->get('telegram_user'); // Bisa null jika tidak ada
    $telegramUser = TelegramUser::find($tgUser["id"]);

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

  /**
  * Cek apakah user memiliki lokasi default.
  */
  protected function userHasDefaultLocation(TelegramUser $user): bool
  {
    $data = $user->data ?? [];
    return !empty($data['default_location']);
  }
}