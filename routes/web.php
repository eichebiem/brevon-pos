<?php

use Illuminate\Support\Facades\Route;

// Staff application only - there is no public website here (SRS §1.3).
Route::redirect('/', '/login')->name('home');

/*
| Phase 1 shells. Each area is behind authentication now; role/permission
| middleware (dashboard.view, pos.access, orders.update-status) is added in
| Phase 2 (FR-007, NFR-003).
*/
Route::middleware(['auth'])->group(function () {
    Route::inertia('admin/dashboard', 'admin/Dashboard')->name('dashboard');
    Route::inertia('pos', 'pos/Index')->name('pos.index');
    Route::inertia('staff/preparation', 'staff/Preparation')->name('staff.preparation');
});

require __DIR__.'/settings.php';
