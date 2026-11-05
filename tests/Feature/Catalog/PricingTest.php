<?php

namespace Tests\Feature\Catalog;

use App\Domain\Access\PermissionCatalog;
use App\Domain\Catalog\CatalogQuery;
use App\Domain\Catalog\PriceResolver;
use App\Domain\Catalog\PriceService;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\Concerns\InteractsWithStaff;
use Tests\TestCase;

/** FR-019, FR-021, BR-015, D-087 · T-CAT-11..17 */
class PricingTest extends TestCase
{
    use InteractsWithStaff;
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAccess();
        $this->owner = $this->staff(PermissionCatalog::OWNER);
        CarbonImmutable::setTestNow('2026-11-05 01:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function later(int $minutes): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes($minutes));
    }

    public function test_a_new_price_closes_the_previous_one_and_history_is_kept(): void
    {
        $prices = $this->app->make(PriceService::class);
        $product = Product::factory()->create();

        $first = $prices->set($this->owner, $product, '40');
        $this->later(5);
        $prices->set($this->owner, $product, '45.50');

        $this->assertSame(2, ProductPrice::query()->count());
        $this->assertEquals(CarbonImmutable::now(), $first->refresh()->effective_until);
        $this->assertSame('45.50', $this->app->make(PriceResolver::class)->current($product, null, $this->mainLocation())?->toDecimal());
        $this->assertSame('40.00', $this->app->make(PriceResolver::class)->current($product, null, $this->mainLocation(), CarbonImmutable::now()->subMinute())?->toDecimal());
    }

    public function test_most_specific_price_wins(): void
    {
        $prices = $this->app->make(PriceService::class);
        $resolver = $this->app->make(PriceResolver::class);
        $product = Product::factory()->create();
        $box = $product->variants()->create(['name' => 'Box of 6', 'is_active' => true]);
        $main = $this->mainLocation();
        $popUp = Branch::factory()->popUp()->create();

        $prices->set($this->owner, $product, '45');
        $prices->set($this->owner, $product, '50', null, $popUp);
        $prices->set($this->owner, $product, '250', $box);
        $prices->set($this->owner, $product, '270', $box, $popUp);
        $this->later(1);

        $this->assertSame('45.00', $resolver->current($product, null, $main)?->toDecimal());
        $this->assertSame('50.00', $resolver->current($product, null, $popUp)?->toDecimal());
        $this->assertSame('250.00', $resolver->current($product, $box, $main)?->toDecimal());
        $this->assertSame('270.00', $resolver->current($product, $box, $popUp)?->toDecimal());
    }

    public function test_special_prices_can_be_ended_but_the_base_price_cannot(): void
    {
        $prices = $this->app->make(PriceService::class);
        $product = Product::factory()->create();
        $popUp = Branch::factory()->popUp()->create();
        $base = $prices->set($this->owner, $product, '45');
        $special = $prices->set($this->owner, $product, '50', null, $popUp);
        $this->later(1);

        $this->actingAs($this->owner)->post("/admin/product-prices/{$base->id}/end")->assertSessionHasErrors('price');
        $this->actingAs($this->owner)->post("/admin/product-prices/{$special->id}/end")->assertSessionHasNoErrors();

        $this->assertSame('45.00', $this->app->make(PriceResolver::class)->current($product, null, $popUp)?->toDecimal());
        $this->assertDatabaseHas('audit_logs', ['event' => 'product.price_ended', 'auditable_id' => $product->id]);
    }

    public function test_price_rows_are_append_only(): void
    {
        $price = $this->app->make(PriceService::class)->set($this->owner, Product::factory()->create(), '45');

        try {
            $price->update(['price' => '1.00']);
            $this->fail('Prices must not be edited in place.');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        $price->delete();
    }

    public function test_two_changes_in_the_same_second_are_refused(): void
    {
        $prices = $this->app->make(PriceService::class);
        $product = Product::factory()->create();
        $prices->set($this->owner, $product, '45');

        $this->expectException(ValidationException::class);
        $prices->set($this->owner, $product, '46');
    }

    public function test_price_changes_over_http_are_validated_and_audited(): void
    {
        $product = Product::factory()->create();
        $other = Product::factory()->create();
        $foreignVariant = $other->variants()->create(['name' => 'X']);

        $this->actingAs($this->owner)->post("/admin/products/{$product->id}/prices", ['price' => '-5'])->assertSessionHasErrors('price');
        $this->actingAs($this->owner)->post("/admin/products/{$product->id}/prices", ['price' => '5', 'variant_id' => $foreignVariant->id])->assertNotFound();
        $this->actingAs($this->owner)->post("/admin/products/{$product->id}/prices", ['price' => '55.25'])->assertSessionHasNoErrors();

        $this->assertSame('55.25', ProductPrice::query()->where('product_id', $product->id)->sole()->price);
        $this->assertDatabaseHas('audit_logs', ['event' => 'product.price_set', 'auditable_id' => $product->id, 'user_id' => $this->owner->id]);
    }

    public function test_sellable_products_respect_status_category_location_and_price(): void
    {
        $prices = $this->app->make(PriceService::class);
        $query = $this->app->make(CatalogQuery::class);
        $main = $this->mainLocation();
        $popUp = Branch::factory()->popUp()->create();

        $make = function (array $attributes = [], bool $finished = false) use ($prices, $main, $popUp): Product {
            $factory = Product::factory();
            $product = ($finished ? $factory->finishedStock() : $factory)->create($attributes);
            $product->locations()->attach([$main->id => ['is_available' => true], $popUp->id => ['is_available' => true]]);
            $prices->set($this->owner, $product, '10');

            return $product;
        };

        $doughnut = $make([], true);
        $coffee = $make();
        $archived = $make(['is_active' => false], true);
        $soldOut = $make(['is_available_for_pos' => false], true);
        $hiddenCategory = $make(['category_id' => Category::factory()->create(['is_active' => false])->id], true);
        $unpriced = Product::factory()->finishedStock()->create();
        $unpriced->locations()->attach($main->id, ['is_available' => true]);
        $notHere = $make([], true);
        $notHere->locations()->updateExistingPivot($main->id, ['is_available' => false]);
        $this->later(1);

        $this->assertEqualsCanonicalizing([$doughnut->id, $coffee->id], $query->sellableAt($main)->pluck('id')->all());
        // Pop-ups: finished stock only, even if a row slipped in for a drink.
        $this->assertEqualsCanonicalizing([$doughnut->id, $notHere->id], $query->sellableAt($popUp)->pluck('id')->all());
    }
}
