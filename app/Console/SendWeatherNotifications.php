<?php

namespace Modules\Weather\Console;

use Illuminate\Console\Command;
use Modules\Weather\Notifications\WeatherSent;
use Modules\Weather\Services\WeatherService;
use Modules\Telegram\Models\TelegramUser;
use Modules\Telegram\Services\Support\TelegramApi;
use Modules\Telegram\Services\Support\TelegramMarkdownHelper;
use Carbon\Carbon;

class SendWeatherNotifications extends Command
{
  protected $signature = 'app:weather-sent';
  protected $description = 'Kirim notifikasi cuaca harian ke pengguna yang mengaktifkan';

  protected $weatherService;
  protected $telegramApi;

  public function __construct(
    WeatherService $weatherService,
    TelegramApi $telegramApi
  ) {
    parent::__construct();
    $this->weatherService = $weatherService;
    $this->telegramApi = $telegramApi;
  }

  public function handle() {
    $this->info('Memulai pengiriman notifikasi cuaca...');

    // Ambil semua user yang mengaktifkan notifikasi cuaca
    $users = TelegramUser::all()->filter(function ($user) {
      $data = $user->data ?? [];
      return ($data['weather_notifications'] ?? false) === true;
    });

    if ($users->isEmpty()) {
      $this->info('Tidak ada pengguna dengan notifikasi cuaca aktif.');
      return 0;
    }

    $today = Carbon::today()->toDateString();
    $sentCount = 0;

    foreach ($users as $user) {
      try {
        $data = $user->data ?? [];
        $defaultLocation = $data['default_location'] ?? [];

        if (empty($defaultLocation)) {
          $this->warn("User tidak menyimpan lokasi default.");
          continue;
        }

        // Cek apakah sudah dikirim hari ini (mencegah pengiriman duplikat)
        $lastWeatherNotification = $data['last_weather_notification'] ?? null;
        if ($lastWeatherNotification !== $today) {
          $this->info("Notification was sent.");
          // Sudah dikirim hari ini, lewati
          continue;
        }

        // Ambil cuaca untuk user
        $weatherData = $this->weatherService->getWeatherForUser($user);
        if (!$weatherData) {
          $this->warn("Gagal ambil cuaca untuk user {$user->telegram_id}");
          \Log::warning("Gagal ambil cuaca untuk user {$user->telegram_id}");
          continue;
        }

        $user->notify(new WeatherSent($weatherData));

        // Simpan catatan pengiriman
        $data['last_weather_notification'] = $today;
        $user->data = $data;
        $user->save();

        $this->info("Notifikasi cuaca terkirim ke {$user->telegram_id}");
        $sentCount++;
      } catch(\Exception $e) {
        \Log::error("Failed to sent weather notification.", [
          'user' => $user->telegram_id,
          'message' => $e->getMessage(),
          'trace' => $e->getTraceAsString()
        ]);

        $this->error("Gagal kirim pesan notifikasi cuaca.");
      }
    }

    $this->info("Selesai. {$sentCount} notifikasi cuaca terkirim.");
    return 0;
  }
}