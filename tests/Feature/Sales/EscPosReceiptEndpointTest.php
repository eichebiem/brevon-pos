<?php

namespace Tests\Feature\Sales;

use App\Domain\Access\PermissionCatalog;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithCheckout;
use Tests\TestCase;

/** D-194: receipts and a test page as ESC/POS bytes for the Android app. */
class EscPosReceiptEndpointTest extends TestCase
{
    use InteractsWithCheckout;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckout();
    }

    public function test_cashier_gets_the_58_mm_receipt_bytes_without_creating_anything(): void
    {
        $product = $this->doughnut('44.00', '5');
        $cashier = $this->cashierOnShift();
        $this->checkout($cashier, $this->payload([['product_id' => $product->id, 'quantity' => 2]]))->assertRedirect();
        $order = Order::query()->sole();
        $audits = AuditLog::query()->count();

        $response = $this->actingAs($cashier)->getJson("/pos/orders/{$order->id}/receipt/escpos")
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJson(['paper' => 58, 'columns' => 32]);
        $bytes = base64_decode((string) $response->json('data'), true);

        $this->assertIsString($bytes);
        $this->assertStringStartsWith("\x1B@", $bytes);
        $this->assertStringContainsString($order->order_number, $bytes);
        $this->assertStringContainsString('2 x Choco Custard', $bytes);
        $this->assertStringContainsString('`88.00', $bytes);
        $this->assertSame($audits, AuditLog::query()->count(), 'reprints are audited by the reprint action');

        $this->actingAs($cashier)->getJson("/pos/orders/{$order->id}/receipt/escpos?paper=80")
            ->assertOk()->assertJson(['paper' => 80, 'columns' => 48]);
        $this->actingAs($cashier)->getJson("/pos/orders/{$order->id}/receipt/escpos?paper=76")
            ->assertUnprocessable();
    }

    public function test_receipt_bytes_follow_the_order_location_rules(): void
    {
        $popUp = Branch::factory()->popUp()->create(['code' => 'POP-SMX1']);
        $product = $this->doughnut('44.00', '5', $popUp, 'Pop-up Glazed');
        $this->checkout($this->cashierOnShift($popUp), $this->payload([['product_id' => $product->id, 'quantity' => 1]]))->assertRedirect();
        $order = Order::query()->sole();

        $mainCashier = $this->staff(PermissionCatalog::CASHIER, $this->mainLocation());
        $this->actingAs($mainCashier)->getJson("/pos/orders/{$order->id}/receipt/escpos")->assertNotFound();

        $mainCashier->syncRoles([]);
        $this->actingAs($mainCashier)->getJson("/pos/orders/{$order->id}/receipt/escpos")->assertForbidden();
    }

    public function test_the_store_logo_prints_at_the_top(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        Storage::disk('public')->put('branding/logo.png', UploadedFile::fake()->image('logo.png', 300, 300)->get());
        Setting::query()->updateOrCreate(['branch_id' => null, 'key' => 'store.logo_path'], ['value' => 'branding/logo.png', 'value_type' => 'string', 'is_sensitive' => false]);
        Cache::flush();
        $product = $this->doughnut('44.00', '5');
        $cashier = $this->cashierOnShift();
        $this->checkout($cashier, $this->payload([['product_id' => $product->id, 'quantity' => 1]]))->assertRedirect();
        $order = Order::query()->sole();

        $bytes = (string) base64_decode((string) $this->actingAs($cashier)->getJson("/pos/orders/{$order->id}/receipt/escpos")->json('data'));
        $this->assertStringContainsString("\x1Dv0\x00\x30\x00", $bytes);
        $this->assertLessThan(strpos($bytes, $order->order_number), strpos($bytes, "\x1Dv0"));
        $this->assertCount(1, Storage::disk('local')->files('printing'));

        $test = (string) base64_decode((string) $this->actingAs($cashier)->getJson('/pos/printer/test')->json('data'));
        $this->assertStringContainsString("\x1Dv0", $test);
    }

    public function test_printer_test_page_needs_pos_access(): void
    {
        $cashier = $this->staff(PermissionCatalog::CASHIER, $this->mainLocation());

        $response = $this->actingAs($cashier)->getJson('/pos/printer/test')
            ->assertOk()->assertJson(['paper' => 58, 'columns' => 32]);
        $this->assertStringContainsString('Printer test', (string) base64_decode((string) $response->json('data')));

        $cashier->syncRoles([]);
        $this->actingAs($cashier)->getJson('/pos/printer/test')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->getJson('/pos/printer/test')->assertUnauthorized();
    }
}
