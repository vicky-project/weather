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
    $this->apiKey = config('weather.openweather.api_key');
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
  * Geocoding: dapatkan koordinat dari nama kota dengan prioritas Indonesia.
  */
  protected function geocodeCity(string $city): ?array
  {
    try {
      // 1. Cari di Indonesia terlebih dahulu
      $indonesianLocation = $this->searchGeocoding($city, 'ID');
      if ($indonesianLocation) {
        return $indonesianLocation;
      }

      // 2. Jika tidak ditemukan, cari tanpa batasan negara
      $response = Http::get($this->geocodingUrl, [
        'q' => $city,
        'limit' => 5,
        'appid' => $this->apiKey,
      ]);

      if (!$response->successful()) {
        return null;
      }

      $locations = $response->json();
      if (empty($locations)) {
        return null;
      }

      // 3. Filter dan urutkan hasil: prioritas nama persis, negara ID, populasi
      $filtered = $this->filterAndSortLocations($locations, $city);
      $best = $filtered[0] ?? null;

      if ($best) {
        Log::info('Geocoding berhasil (fallback)', [
          'city' => $city,
          'found' => $best['name'],
          'country' => $best['country'],
          'lat' => $best['lat'],
          'lon' => $best['lon']
        ]);
        return [
          'lat' => $best['lat'],
          'lon' => $best['lon'],
          'name' => $best['name'],
          'country' => $best['country'] ?? null
        ];
      }

      return null;
    } catch (Throwable $e) {
      Log::error('Geocoding exception', [
        'city' => $city,
        'error' => $e->getMessage()
      ]);
      return null;
    }
  }

  /**
  * Cari geocoding dengan filter negara.
  */
  protected function searchGeocoding(string $city, ?string $countryCode = null): ?array
  {
    $params = [
      'q' => $city,
      'limit' => 5,
      'appid' => $this->apiKey,
    ];
    if ($countryCode) {
      $params['country'] = $countryCode;
    }

    $response = Http::get($this->geocodingUrl, $params);
    if (!$response->successful()) {
      return null;
    }

    $locations = $response->json();
    if (empty($locations)) {
      return null;
    }

    $filtered = $this->filterAndSortLocations($locations, $city);
    $best = $filtered[0] ?? null;

    if ($best) {
      Log::info('Geocoding berhasil (dengan filter negara)', [
        'city' => $city,
        'country' => $countryCode,
        'found' => $best['name'],
        'lat' => $best['lat'],
        'lon' => $best['lon']
      ]);
      return [
        'lat' => $best['lat'],
        'lon' => $best['lon'],
        'name' => $best['name'],
        'country' => $best['country'] ?? null
      ];
    }

    return null;
  }

  /**
  * Filter dan urutkan hasil geocoding berdasarkan skor relevansi.
  */
  protected function filterAndSortLocations(array $locations, string $searchCity): array
  {
    $searchCityLower = strtolower(trim($searchCity));

    $scored = array_map(function ($loc) use ($searchCityLower) {
      $score = 0;
      $nameLower = strtolower($loc['name']);
      $country = $loc['country'] ?? '';

      // Bonus jika nama sama persis
      if ($nameLower === $searchCityLower) {
        $score += 100;
      }
      // Bonus jika nama mengandung kata yang dicari
      elseif (strpos($nameLower, $searchCityLower) !== false) {
        $score += 50;
      }
      // Bonus jika di Indonesia
      if ($country === 'ID') {
        $score += 80;
      }
      // Bonus jika populasi besar (jika ada)
      if (isset($loc['population'])) {
        $score += min(50, floor($loc['population'] / 1000000));
      }
      return array_merge($loc, ['score' => $score]);
    },
      $locations);

    // Urutkan berdasarkan skor tertinggi
    usort($scored,
      function ($a, $b) {
        return $b['score'] <=> $a['score'];
      });

    return $scored;
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
        'rise' => isset($sys->sunrise) ? date('H:i',
          $sys->sunrise) : null,
        'set' => isset($sys->sunset) ? date('H:i',
          $sys->sunset) : null,
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