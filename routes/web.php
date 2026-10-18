<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DashboardController::class, 'index']);
Route::get('/api/readings', [DashboardController::class, 'readings']);
Route::view('/privacy', 'privacy');
Route::view('/terms', 'terms');
