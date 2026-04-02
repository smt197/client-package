<?php

use App\Http\Controllers\OrchestratorController;

use App\Http\Controllers\AiAgentController;
use Orion\Facades\Orion;
use Illuminate\Support\Facades\Route;

Route::post('/orchestrate', [OrchestratorController::class, 'generateFromAngular']);
Orion::resource('agents', AiAgentController::class);

