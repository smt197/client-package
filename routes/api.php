<?php

use App\Http\Controllers\OrchestratorController;

use Orion\Facades\Orion;
use Illuminate\Support\Facades\Route;

Route::post('/orchestrate', [OrchestratorController::class, 'generateFromAngular']);

