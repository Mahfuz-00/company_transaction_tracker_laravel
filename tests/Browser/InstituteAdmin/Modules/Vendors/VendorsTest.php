<?php

namespace Tests\Browser\InstituteAdmin\Modules\Vendors;

use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Browser\Support\DuskSupport;
use Tests\DuskTestCase;

/**
 * INSTITUTE ADMIN → MODULES → VENDORS.
 *
 * Routes (Route::resource except create/edit/show + a history JSON endpoint):
 *   GET    /meals/vendors              (`meals.vendors.index`,   vendors.view)
 *   POST   /meals/vendors              (`meals.vendors.store`,   vendors.manage)
 *   PUT    /meals/vendors/{id}         (`meals.vendors.update`,  vendors.manage)
 *   DELETE /meals/vendors/{id}         (`meals.vendors.destroy`, vendors.manage)
 *
 * VendorController::index ensures the institution's HUB vendor exists;
 * ::destroy refuses to delete a vendor with purchase history.
 */
class VendorsTest extends DuskTestCase
{
    use DuskSupport;
    use RefreshDatabase;

    public function test_institute_admin_creates_a_vendor(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution(['name' => 'North South University Dorm']);
        $admin = $this->makeInstitutionAdmin($institution);

        $this->step('InstituteAdmin', 'Vendors', 'POST a vendor', __LINE__);

        $this->actingAs($admin)
            ->post('/meals/vendors', [
                'name' => 'Fresh Vegetable Supplier',
                'status' => 'active',
                'category' => 'groceries',
            ])
            ->assertSessionHas('success');

        $this->step('InstituteAdmin', 'Vendors', 'assert vendor bound to institution', __LINE__);

        $vendor = Vendor::where('name', 'Fresh Vegetable Supplier')->first();
        $this->assertNotNull($vendor);
        $this->assertSame($institution->id, $vendor->institution_id);
    }

    public function test_a_vendor_with_purchase_history_cannot_be_deleted(): void
    {
        $this->seedRbac();
        $institution = $this->makeInstitution();
        $admin = $this->makeInstitutionAdmin($institution);

        // Create the vendor through the real endpoint, then record an expense
        // against it so purchase history exists.
        $this->actingAs($admin)->post('/meals/vendors', [
            'name' => 'Locked Vendor', 'status' => 'active', 'category' => 'groceries',
        ]);
        $vendor = Vendor::where('name', 'Locked Vendor')->firstOrFail();

        $this->actingAs($admin)->post('/meals/expenses', [
            'amount' => 800, 'description' => 'Vendor purchase', 'category' => 'Groceries',
            'vendor_id' => $vendor->id,
        ]);

        $this->step('InstituteAdmin', 'Vendors', 'DELETE refused (purchase history)', __LINE__);

        $this->actingAs($admin)
            ->delete("/meals/vendors/{$vendor->id}")
            ->assertSessionHas('error');

        $this->assertDatabaseHas('vendors', ['id' => $vendor->id]);
    }
}
