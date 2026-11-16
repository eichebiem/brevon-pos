<?php

namespace App\Http\Controllers\Pos;

use App\Domain\Printing\EscPosReceipt;
use App\Domain\Printing\ReceiptLogo;
use App\Domain\Settings\StoreSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * D-194: a test page in ESC/POS bytes (base64) for the Android app's printer
 * settings, to check the paper width, the peso sign and the logo. Route
 * middleware: permission:pos.access. Reads only the store name and logo.
 */
class PrinterController extends Controller
{
    public function test(Request $request, EscPosReceipt $printer, StoreSettings $settings, ReceiptLogo $logo): JsonResponse
    {
        $paper = (int) ($request->validate(['paper' => ['nullable', 'in:58,80']])['paper'] ?? 58);

        return response()->json([
            'paper' => $paper,
            'columns' => EscPosReceipt::WIDTHS[$paper],
            'data' => base64_encode($printer->testPage((string) $settings->get('store.name'), $paper, $logo->bitmap($settings->get('store.logo_path')))),
        ])->header('Cache-Control', 'no-store');
    }
}
