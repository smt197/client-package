<?php

use App\Http\Controllers\OrchestratorController;
use Illuminate\Support\Facades\Route;

Route::post('/orchestrate', [OrchestratorController::class, 'generateFromAngular']);
