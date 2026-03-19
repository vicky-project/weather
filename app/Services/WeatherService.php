<?php

namespace Modules\Weather\Services;

use RakibDevs\Weather\Weather;
use Modules\Telegram\Models\TelegramUser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class WeatherService
{
  protected Weather $weatherClient;
  protected int $cacheDuration = 1800; // 30 menit dalam detik

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
  *
  * @param array $location (berisi 'city' atau 'latitude'+'longitude')
  * @return array|null
  */
  protected function fetchWeatherWithCache(array $location): ?array
  {
    Log::debug("location", $location);
    if (isset($location['city'])) {
      $weatherData = $this->weatherClient->getCurrentByCity($location['city']);
    } else {
      $weatherData = $this->weatherClient->getCurrentByCord(
        $location['latitude'],
        $location['longitude']
      );
    }
    Log::debug("weather data", ["data" => $weatherData]);

    $cacheKey = $this->generateCacheKey($location);

    return Cache::remember($cacheKey, $this->cacheDuration, function () use ($location) {
      try {
        if (isset($location['city'])) {
          $weatherData = $this->weatherClient->getCurrentByCity($location['city']);
        } else {
          $weatherData = $this->weatherClient->getCurrentByCord(
            $location['latitude'],
            $location['longitude']
          );
        }

        return $this->formatWeatherData($weatherData);
      } catch (\Exception $e) {
        Log::error('Gagal mengambil data cuaca dari API', [
          'location' => $location,
          'error' => $e->getMessage(),
          'trace' => $e->getTraceAsString()
        ]);
        return null;
      }
    });
  }

  /**
  * Generate unique cache key based on location.
  */
  protected function generateCacheKey(array $location): string
  {
    if (isset($location['city'])) {
      return 'weather_' . md5(strtolower(trim($location['city'])));
    }
    return 'weather_' . md5("{$location['latitude']},{$location['longitude']}");
  }

  /**
  * Ekstrak lokasi default dari field 'data' pengguna.
  */
  protected function getUserDefaultLocation(TelegramUser $telegramUser): ?array
  {
    $data = $telegramUser->data ?? [];
    return $data['default_location'] ?? null;
  }

  /**
  * Simpan atau perbarui lokasi default pengguna.
  */
  public function updateUserSettings(TelegramUser $telegramUser, array $locationData, bool $notificationsEnabled): void
  {
    $currentData = $telegramUser->data ?? [];
    $currentData['default_location'] = $locationData;
    $currentData['weather_notifications'] = $notificationsEnabled;
    $telegramUser->data = $currentData;
    $telegramUser->save();

    // Hapus cache lama jika ada (opsional, karena lokasi baru akan punya key berbeda)
    // Tapi jika user mengganti lokasi, kita bisa hapus cache untuk lokasi lama? Tidak perlu karena key berbeda.
  }

  /**
  * Format data dari package.
  */
  protected function formatWeatherData(object $rawData): array
  {
    $coord = $rawData->coord ?? null;
    $main = $rawData->main ?? null;
    $weather = $rawData->weather[0] ?? null;
    $wind = $rawData->wind ?? null;
    $clouds = $rawData->clouds ?? null;
    $sys = $rawData->sys ?? null;

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
}