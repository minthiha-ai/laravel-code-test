<?php

use App\Http\Controllers\ExportEmployeesController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:api')->group(function () {
    Route::get('/employees/export', ExportEmployeesController::class);
});
