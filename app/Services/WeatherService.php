<?php

namespace Modules\Weather\Services;

use RakibDevs\Weather\Weather;
use Modules\Telegram\Models\TelegramUser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class WeatherService
{
  protected Weather $weatherClient;
  protected int $cacheDuration = 1800; // 30 menit
  protected string $geocodingUrl = 'http://api.openweathermap.org/geo/1.0/direct';
  protected string $apiKey;

  public function __construct() {
    $this->weatherClient = new Weather();
    $this->apiKey = config('services.openweather.api_key');
  }

  /**
  * Mendapatkan data cuaca untuk pengguna Telegram tertentu.
  */
  public function getWeatherForUser(TelegramUser $telegramUser): ?array
  {
    $location = $this->getUserDefaultLocation($telegramUser);
    if (!$location) {
      return null;
    }
    return $this->fetchWeatherWithCache($location);
  }

  /**
  * Ambil data cuaca berdasarkan input manual.
  */
  public function getWeatherByInput(?string $city, ?float $latitude, ?float $longitude): ?array
  {
    $location = [];
    if ($city) {
      $location['city'] = $city;
    } elseif ($latitude && $longitude) {
      $location['latitude'] = $latitude;
      $location['longitude'] = $longitude;
    } else {
      return null;
    }
    return $this->fetchWeatherWithCache($location);
  }

  /**
  * Ambil data cuaca dengan cache.
  */
  protected function fetchWeatherWithCache(array $location): ?array
  {
    $cacheKey = $this->generateCacheKey($location);

    // Coba ambil dari cache
    $cached = Cache::get($cacheKey);
    if ($cached !== null) {
      return $cached;
    }

    // Jika tidak ada, panggil API
    try {
      $weatherData = $this->fetchFromApi($location);
      if ($weatherData) {
        Cache::put($cacheKey, $weatherData, $this->cacheDuration);
        return $weatherData;
      }
    } catch (Throwable $e) {
      Log::error('Gagal mengambil data cuaca dari API', [
        'location' => $location,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
      ]);
    }

    return null;
  }

  /**
  * Panggil API OpenWeatherMap dengan fallback geocoding.
  */
  protected function fetchFromApi(array $location): ?array
  {
    // Jika sudah punya koordinat, langsung gunakan
    if (isset($location['latitude']) && isset($location['longitude'])) {
      return $this->getWeatherByCoordinates($location['latitude'], $location['longitude']);
    }

    // Jika hanya punya nama kota, lakukan geocoding dulu
    if (isset($location['city'])) {
      return $this->getWeatherByCityName($location['city']);
    }

    return null;
  }

  /**
  * Dapatkan cuaca berdasarkan koordinat.
  */
  protected function getWeatherByCoordinates(float $lat, float $lon): ?array
  {
    try {
      $rawData = $this->weatherClient->getCurrentByCord($lat, $lon);
      return $this->formatWeatherData($rawData);
    } catch (Throwable $e) {
      Log::warning('Gagal get weather by coordinates', [
        'lat' => $lat,
        'lon' => $lon,
        'error' => $e->getMessage()
      ]);
      return null;
    }
  }

  /**
  * Dapatkan cuaca berdasarkan nama kota dengan fallback geocoding.
  */
  protected function getWeatherByCityName(string $city): ?array
  {
    // Coba langsung dengan nama kota (kemungkinan berhasil jika nama unik)
    try {
      $rawData = $this->weatherClient->getCurrentByCity($city);
      return $this->formatWeatherData($rawData);
    } catch (Throwable $e) {
      Log::info('Lang sunggagal, coba geocoding', ['city' => $city]);
    }

    // Fallback: lakukan geocoding untuk mendapatkan koordinat
    $coordinates = $this->geocodeCity($city);
    if (!$coordinates) {
      return null;
    }

    // Ambil cuaca berdasarkan koordinat
    return $this->getWeatherByCoordinates($coordinates['lat'], $coordinates['lon']);
  }

  /**
  * Geocoding: dapatkan koordinat dari nama kota.
  */
  protected function geocodeCity(string $city): ?array
  {
    try {
      $response = Http::get($this->geocodingUrl, [
        'q' => $city,
        'limit' => 5,
        'appid' => $this->apiKey,
      ]);

      if (!$response->successful()) {
        Log::warning('Geocoding API gagal', ['city' => $city, 'status' => $response->status()]);
        return null;
      }

      $locations = $response->json();
      if (empty($locations)) {
        Log::info('Kota tidak ditemukan di geocoding', ['city' => $city]);
        return null;
      }

      // Ambil lokasi pertama (paling relevan)
      $bestMatch = $locations[0];

      Log::info('Geocoding berhasil', [
        'city' => $city,
        'found' => $bestMatch['name'],
        'country' => $bestMatch['country'],
        'lat' => $bestMatch['lat'],
        'lon' => $bestMatch['lon']
      ]);

      return [
        'lat' => $bestMatch['lat'],
        'lon' => $bestMatch['lon'],
        'name' => $bestMatch['name'],
        'country' => $bestMatch['country'] ?? null
      ];
    } catch (Throwable $e) {
      Log::error('Geocoding exception', [
        'city' => $city,
        'error' => $e->getMessage()
      ]);
      return null;
    }
  }

  /**
  * Format data cuaca dengan aman.
  */
  protected function formatWeatherData(object $rawData): array
  {
    $coord = $rawData->coord ?? null;
    $main = $rawData->main ?? null;
    $weather = $rawData->weather[0] ?? null;
    $wind = $rawData->wind ?? null;
    $clouds = $rawData->clouds ?? null;
    $sys = $rawData->sys ?? null;
    $visibility = $rawData->visibility ?? null;

    return [
      'location' => [
        'name' => $rawData->name ?? 'Lokasi tidak diketahui',
        'country' => $sys->country ?? null,
        'latitude' => $coord->lat ?? null,
        'longitude' => $coord->lon ?? null,
      ],
      'current' => [
        'temperature' => round($main->temp ?? 0),
        'feels_like' => round($main->feels_like ?? 0),
        'humidity' => $main->humidity ?? 0,
        'pressure' => $main->pressure ?? 0,
        'wind_speed' => $wind->speed ?? 0,
        'clouds' => $clouds->all ?? 0,
        'visibility' => $visibility,
      ],
      'weather' => [
        'main' => $weather->main ?? null,
        'description' => $weather->description ?? null,
        'icon' => $weather->icon ?? null,
      ],
      'sun' => [
        'rise' => isset($sys->sunrise) ? date('H:i', $sys->sunrise) : null,
        'set' => isset($sys->sunset) ? date('H:i', $sys->sunset) : null,
      ],
      'updated_at' => now()->toIso8601String(),
    ];
  }

  /**
  * Generate cache key.
  */
  protected function generateCacheKey(array $location): string
  {
    if (isset($location['city'])) {
      return 'weather_' . md5(strtolower(trim($location['city'])));
    }
    return 'weather_' . md5("{$location['latitude']},{$location['longitude']}");
  }

  /**
  * Ambil lokasi default dari data pengguna.
  */
  protected function getUserDefaultLocation(TelegramUser $telegramUser): ?array
  {
    $data = $telegramUser->data ?? [];
    return $data['default_location'] ?? null;
  }

  /**
  * Simpan pengaturan pengguna.
  */
  public function updateUserSettings(TelegramUser $telegramUser, array $locationData, bool $notificationsEnabled): void
  {
    $currentData = $telegramUser->data ?? [];
    $currentData['default_location'] = $locationData;
    $currentData['weather_notifications'] = $notificationsEnabled;
    $telegramUser->data = $currentData;
    $telegramUser->save();
  }
}