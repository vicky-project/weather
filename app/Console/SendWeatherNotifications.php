<?php

namespace Modules\Weather\Console;

use Illuminate\Console\Command;
use Modules\Weather\Notifications\WeatherSent;
use Modules\Weather\Services\WeatherService;
use Modules\Telegram\Models\TelegramUser;
use Carbon\Carbon;

class SendWeatherNotifications extends Command
{
  protected $signature = 'app:weather-sent';
  protected $description = 'Kirim notifikasi cuaca harian ke pengguna yang mengaktifkan';

  protected $weatherService;

  public function __construct(
    WeatherService $weatherService
  ) {
    parent::__construct();
    $this->weatherService = $weatherService;
  }

  public function handle() {
    $this->info('Memulai pengiriman notifikasi cuaca...');
    \Log::info("Command SendWeatherNotifications started...");

    // Ambil semua user yang mengaktifkan notifikasi cuaca
    $users = TelegramUser::all()->filter(function ($user) {
      $data = $user->data ?? [];
      return ($data['weather_notifications'] ?? false) === true;
    });

    if ($users->isEmpty()) {
      $this->info('Tidak ada pengguna dengan notifikasi cuaca aktif.');
      \Log::info("No users with weather notifications enabled");
      return 0;
    }

    $today = Carbon::today()->toDateString();
    $slot = date("H") < 12 ? "morning" : "evening";
    $sentCount = 0;
    $skipCount = 0;
    $failCount = 0;

    foreach ($users as $user) {
      try {
        $data = $user->data ?? [];
        $defaultLocation = $data['default_location'] ?? [];

        if (empty($defaultLocation)) {
          $this->info("User tidak menyimpan lokasi default.");
          \Log::warning("User has no default location", [
            "telegram_id" => $user->telegram_id
          ]);
          $failCount++;
          continue;
        }

        // Ambil history pengiriman notifikasi cuaca
        $weatherNotifications = $data['weather_notifications'] ?? [];

        // Cek apakah untuk slot ini sudah dikirim hari ini
        if (isset($weatherNotifications[$today][$slot]) && $weatherNotifications[$today][$slot] === true) {
          $this->info("Notifikasi cuaca sudah dikirim untuk slot {$slot} hari ini, lewati.");
          \Log::debug("Weather notification already sent for {$slot} slot", [
            "telegram_id" => $user->telegram_id
          ]);
          $skipCount++;
          continue;
        }

        // Ambil cuaca untuk user
        $weatherData = $this->weatherService->getWeatherForUser($user);
        if (!$weatherData) {
          $this->warn("Gagal ambil cuaca untuk user {$user->telegram_id}");
          \Log::warning("Failed to get weather for user", [
            "telegram_id" => $user->telegram_id
          ]);
          $failCount++;
          continue;
        }

        $user->notify(new WeatherSent($weatherData));

        // Tandai slot ini sebagai sudah dikirim
        $weatherNotifications[$today][$slot] = true;
        $data['weather_notifications'] = $weatherNotifications;
        $user->data = $data;
        $user->save();

        $this->info("Notifikasi cuaca terkirim ke {$user->telegram_id} (slot {$slot})");
        \Log::info("Weather notification sent", [
          "telegram_id" => $user->telegram_id,
          "slot" => $slot
        ]);
        $sentCount++;
      } catch(\Exception $e) {
        \Log::error("Failed to sent weather notification.", [
          'telegram_id' => $user->telegram_id,
          'message' => $e->getMessage(),
          'trace' => $e->getTraceAsString()
        ]);

        $this->error("Gagal kirim pesan notifikasi cuaca.");
        $failCount++;
      }
    }

    $this->info("Selesai. {$sentCount} notifikasi terkirim, {$skipCount} dilewati, {$failCount} gagal");
    \Log::info("Command SendWeatherNotifications finished", [
      "sent" => $sentCount,
      "skip" => $skipCount,
      "fail" => $failCount
    ]);
    return 0;
  }
}