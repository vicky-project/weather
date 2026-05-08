@extends('telegram::layouts.mini-app')

@section('title', 'Informasi Cuaca')

@section('content')
<div class="container py-0" style="max-width:600px; margin:0 auto;">
  <div id="weather-app">
    <div id="weather-view" style="display:none;"></div>
    <div id="settings-view" style="display:none;"></div>

    <!-- Modal Bootstrap untuk detail forecast -->
    <div class="modal fade" id="forecastModal" tabindex="-1" aria-labelledby="forecastModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header" style="border-bottom-color: var(--tg-theme-section-separator-color);">
            <h5 class="modal-title" id="forecastModalLabel">Detail Cuaca</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" style="filter: invert(1);"></button>
          </div>
          <div class="modal-body" id="forecastModalBody">
            <!-- konten akan diisi JavaScript -->
          </div>
          <div class="modal-footer" style="border-top-color: var(--tg-theme-section-separator-color);">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
@endsection

@push('styles')
<style>
  body {
    background-color: var(--tg-theme-bg-color) !important;
    color: var(--tg-theme-text-color) !important;
    padding: 0 !important;
    margin: 0 !important;
  }
  .container {
    padding-left: 0 !important;
    padding-right: 0 !important;
  }
  .card {
    background-color: var(--tg-theme-secondary-bg-color) !important;
    border-color: var(--tg-theme-section-separator-color) !important;
    border-radius: 0;
  }
  .card-header {
    background-color: var(--tg-theme-button-color) !important;
    color: var(--tg-theme-button-text-color) !important;
    border-radius: 0;
  }
  .form-control, .input-group-text {
    background-color: var(--tg-theme-bg-color) !important;
    color: var(--tg-theme-text-color) !important;
    border-color: var(--tg-theme-section-separator-color) !important;
  }
  .btn-primary {
    background-color: var(--tg-theme-button-color) !important;
    border-color: var(--tg-theme-button-color) !important;
    color: var(--tg-theme-button-text-color) !important;
  }
  .btn-outline-secondary {
    border-color: var(--tg-theme-section-separator-color) !important;
    color: var(--tg-theme-hint-color) !important;
  }
  .text-muted {
    color: var(--tg-theme-hint-color) !important;
  }
  .weather-icon img {
    width: 80px;
    height: 80px;
  }
  .temperature {
    font-size: 3rem;
    font-weight: 300;
  }
  .detail-item {
    background-color: var(--tg-theme-bg-color) !important;
    border: 1px solid var(--tg-theme-section-separator-color);
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
    text-align: center;
    border-radius: 12px;
    padding: 10px;
  }
  .detail-item i {
    font-size: 1.5rem;
    color: var(--tg-theme-button-color);
  }
  .detail-item .value, .detail-item .label {
    color: var(--tg-theme-text-color);
  }
  .detail-item .label {
    opacity: 0.8;
  }
  .forecast-hour-card {
    background-color: var(--tg-theme-bg-color) !important;
    border: 1px solid var(--tg-theme-section-separator-color) !important;
    border-radius: 12px;
    padding: 8px;
    text-align: center;
    min-width: 80px;
    cursor: pointer;
    transition: transform 0.2s, background-color 0.2s;
  }
  .forecast-hour-card:hover {
    background-color: var(--tg-theme-hint-color) !important;
  }
  .modal-content {
    background-color: var(--tg-theme-bg-color);
    color: var(--tg-theme-text-color);
  }
  .modal-header, .modal-footer {
    border-color: var(--tg-theme-section-separator-color);
  }
  .forecast-hour-card:active {
    transform: scale(0.96);
  }
  .pagination-wrapper {
    overflow-x: auto;
    text-align: center;
    margin: 1rem 0;
  }
  .pagination {
    display: inline-flex;
    flex-wrap: nowrap;
    gap: 0.25rem;
  }
  .page-item {
    flex-shrink: 0;
  }
  .error-container {
    background-color: var(--tg-theme-secondary-bg-color);
    border-left: 4px solid #dc3545;
    padding: 16px;
    margin: 16px;
    border-radius: 12px;
  }
  .error-container .error-message {
    color: #dc3545;
    font-weight: 500;
    margin-bottom: 8px;
  }
  .error-container .error-detail {
    font-size: 0.8rem;
    color: var(--tg-theme-hint-color);
    word-break: break-word;
  }
</style>
@endpush

@push('scripts')
<script src="//cdn.jsdelivr.net/npm/eruda"></script>
<script>
  eruda.init();
</script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
  const BASE_URL = '{{ rtrim(config("app.url"), "/") }}';

  {!! file_get_contents(module_path('weather', 'resources/assets/js/core.js')); !!}
  {!! file_get_contents(module_path('weather', 'resources/assets/js/page.js')); !!}
  {!! file_get_contents(module_path('weather', 'resources/assets/js/main.js')); !!}
</script>
@endpush