<?php

namespace Modules\Weather\Services;

use RakibDevs\Weather\Weather;
use Modules\Telegram\Models\TelegramUser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class WeatherService
{
  protected Weather $weatherClient;
  protected int $cacheDuration = 1800; // 30 menit

  public function __construct() {
    $this->weatherClient = new Weather();
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
  * Panggil API OpenWeatherMap via package.
  */
  protected function fetchFromApi(array $location): ?array
  {
    try {
      if (isset($location['city'])) {
        $rawData = $this->weatherClient->getCurrentByCity($location['city']);
      } else {
        $rawData = $this->weatherClient->getCurrentByCord(
          $location['latitude'],
          $location['longitude']
        );
      }

      // Validasi bahwa respons adalah objek dan memiliki properti yang diperlukan
      if (!is_object($rawData) || !isset($rawData->weather) || !isset($rawData->main)) {
        throw new \Exception('Respons API tidak valid atau kota tidak ditemukan');
      }

      return $this->formatWeatherData($rawData);
    } catch (Throwable $e) {
      // Tangkap semua error termasuk notice/warning dari package
      throw $e; // Lempar ulang agar ditangani oleh fetchWeatherWithCache
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